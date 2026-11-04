<?php

namespace App\Jobs;

use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Models\TelegramUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class SendLobbyNotificationsBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    /**
     * Передаются ID записей TelegramUser, не Telegram chat ID.
     *
     * @param array<int, int> $telegramUserIds
     */
    public function __construct(
        public int $lobbyId,
        public int $creatorTelegramId,
        public string $creatorName,
        public array $telegramUserIds,
    ) {
        $this->onQueue('telegram');
    }

    public function handle(Api $telegram): void
    {
        $lobby = Lobby::with('clan')->find($this->lobbyId);

        if (
            !$lobby ||
            $lobby->status !== 'waiting' ||
            empty($lobby->game_room_code)
        ) {
            Log::info('Личное уведомление пропущено: лобби недоступно', [
                'lobby_id' => $this->lobbyId,
            ]);

            return;
        }

        $gameLink = 'https://play.suspects.io/?code='
            . rawurlencode($lobby->game_room_code);

        $creatorLink = 'tg://user?id=' . $this->creatorTelegramId;

        $creatorName = htmlspecialchars(
            $this->creatorName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $roomCode = htmlspecialchars(
            $lobby->game_room_code,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $clanLabel = $lobby->clan
            ? ' [' . htmlspecialchars(
                $lobby->clan->name,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . ']'
            : '';

        $text = "🔔 Новое лобби!\n\n"
            . "🎮 Лобби #{$lobby->id}{$clanLabel}\n"
            . "👑 Создал: <a href=\"{$creatorLink}\"><b>{$creatorName}</b></a>\n"
            . "🔑 Код: {$roomCode}\n\n"
            . '👇 Вход в игру:';

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🎮 Войти в игру',
                        'url' => $gameLink,
                    ],
                ],
                [
                    [
                        'text' => '📋 Скопировать код',
                        'copy_text' => [
                            'text' => $lobby->game_room_code,
                        ],
                    ],
                ],
            ],
        ];

        $userIds = array_values(array_unique(array_map(
            'intval',
            $this->telegramUserIds
        )));

        $users = TelegramUser::query()
            ->whereIn('id', $userIds)
            ->where('telegram_id', '!=', $this->creatorTelegramId)
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($users as $user) {
            // Не отправляем уведомление пользователю, который уже играет.
            $isActive = \App\Models\LobbyPlayer::query()
                ->where('telegram_user_id', $user->id)
                ->whereHas('lobby', function ($query) {
                    $query->whereIn('status', ['waiting', 'playing']);
                })
                ->exists();

            if ($isActive) {
                $skipped++;
                continue;
            }

            // Защита от повторной отправки в рамках обычных повторов Job.
            $alreadySent = LobbyNotification::query()
                ->where('lobby_id', $lobby->id)
                ->where('telegram_user_id', $user->id)
                ->where('chat_type', 'private')
                ->exists();

            if ($alreadySent) {
                $skipped++;
                continue;
            }

            try {
                $response = $telegram->sendMessage([
                    'chat_id' => $user->telegram_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(
                        $replyMarkup,
                        JSON_UNESCAPED_UNICODE
                    ),
                ]);

                $messageId = $response->getMessageId();

                LobbyNotification::create([
                    'lobby_id' => $lobby->id,
                    'telegram_user_id' => $user->id,
                    'telegram_message_id' => $messageId,
                    'chat_id' => (string) $user->telegram_id,
                    'chat_type' => 'private',
                ]);

                $sent++;

                try {
                    $telegram->pinChatMessage([
                        'chat_id' => $user->telegram_id,
                        'message_id' => $messageId,
                        'disable_notification' => true,
                    ]);
                } catch (Throwable $e) {
                    Log::warning('Не удалось закрепить личное уведомление', [
                        'lobby_id' => $lobby->id,
                        'telegram_id' => $user->telegram_id,
                        'message_id' => $messageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (Throwable $e) {
                $failed++;

                Log::warning('Не удалось отправить личное уведомление', [
                    'lobby_id' => $lobby->id,
                    'telegram_id' => $user->telegram_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Пакет личных уведомлений обработан', [
            'lobby_id' => $lobby->id,
            'users_in_batch' => count($userIds),
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }
}
