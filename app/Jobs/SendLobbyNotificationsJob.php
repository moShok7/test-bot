<?php

namespace App\Jobs;

use App\Models\BotGroup;
use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Models\LobbyPlayer;
use App\Models\TelegramUser;
use App\Services\Telegram\UserTelegramGroupsService;
use App\Services\Telegram\UserTelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class SendLobbyNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public int $lobbyId,
        public int $creatorTelegramId,
        public string $creatorName,
        public int $count = 100,
    ) {
        $this->onQueue('telegram');
    }

    public function handle(
        Api $telegram,
        UserTelegramService $userTelegram,
        UserTelegramGroupsService $userTelegramGroups
    ): void {
        $lobby = Lobby::with('clan')->find($this->lobbyId);

        if (
            !$lobby ||
            $lobby->status !== 'waiting' ||
            empty($lobby->game_room_code)
        ) {
            Log::info('Рассылка пропущена: лобби недоступно', [
                'lobby_id' => $this->lobbyId,
            ]);

            return;
        }

        $this->deleteOldNotifications(
            $telegram,
            $userTelegram
        );

        // Повторно проверяем состояние после очистки.
        $lobby->refresh();

        if (
            $lobby->status !== 'waiting' ||
            empty($lobby->game_room_code)
        ) {
            return;
        }

        $this->dispatchPrivateBatches($lobby);

        $gameLink = 'https://play.suspects.io/?code='
            . rawurlencode($lobby->game_room_code);

        $creatorLink = 'tg://user?id='
            . $this->creatorTelegramId;

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

        // 1. Группы через Bot API.
        $groups = BotGroup::query()
            ->where('is_active', true)
            ->get();

        foreach ($groups as $group) {
            if ($this->notificationExists(
                $lobby->id,
                (string) $group->chat_id,
                'group'
            )) {
                continue;
            }

            try {
                $response = $telegram->sendMessage([
                    'chat_id' => $group->chat_id,
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
                    'telegram_user_id' => null,
                    'telegram_message_id' => $messageId,
                    'chat_id' => (string) $group->chat_id,
                    'chat_type' => 'group',
                ]);

                try {
                    $telegram->pinChatMessage([
                        'chat_id' => $group->chat_id,
                        'message_id' => $messageId,
                        'disable_notification' => true,
                    ]);
                } catch (Throwable $e) {
                    Log::warning('Не удалось закрепить сообщение Bot API', [
                        'lobby_id' => $lobby->id,
                        'chat_id' => $group->chat_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Ошибка отправки в группу Bot API', [
                    'lobby_id' => $lobby->id,
                    'chat_id' => $group->chat_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Внешние группы через MTProto.
        try {
            $externalGroups = $userTelegramGroups->getExternalGroups();

            foreach ($externalGroups as $group) {
                $chatId = (string) $group['chat_id'];

                if ($this->notificationExists(
                    $lobby->id,
                    $chatId,
                    'mtproto_group'
                )) {
                    continue;
                }

                try {
                    $mtprotoText =
                        "🔔 <b>Новое лобби!</b>\n\n"
                        . "🎮 <b>Лобби #{$lobby->id}{$clanLabel}</b>\n"
                        . "👑 Создал: <a href=\"{$creatorLink}\"><b>{$creatorName}</b></a>\n"
                        . "🔑 <b>Код: <code>{$roomCode}</code></b>\n\n"
                        . "👇 <b>Вход в игру:</b>\n"
                        . $gameLink;

                    $messageId = $userTelegram->sendMessage(
                        $group['chat_id'],
                        $mtprotoText
                    );

                    if (!$messageId) {
                        throw new \RuntimeException(
                            'MTProto не вернул ID отправленного сообщения'
                        );
                    }

                    LobbyNotification::create([
                        'lobby_id' => $lobby->id,
                        'telegram_user_id' => null,
                        'telegram_message_id' => $messageId,
                        'chat_id' => $chatId,
                        'chat_type' => 'mtproto_group',
                    ]);

                    try {
                        $userTelegram->pinMessage(
                            $group['chat_id'],
                            $messageId
                        );
                    } catch (Throwable $e) {
                        Log::warning('Не удалось закрепить MTProto сообщение', [
                            'lobby_id' => $lobby->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Ошибка отправки через MTProto', [
                        'lobby_id' => $lobby->id,
                        'chat_id' => $chatId,
                        'title' => $group['title'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            Log::error('Не удалось получить внешние группы MTProto', [
                'lobby_id' => $lobby->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Рассылка уведомлений завершена', [
            'lobby_id' => $lobby->id,
            'batch_size' => max(1, $this->count),
        ]);
    }

    /**
     * Создаёт отдельные Jobs для личных уведомлений.
     */
    private function dispatchPrivateBatches(Lobby $lobby): void
    {
        $activePlayerIds = LobbyPlayer::query()
            ->whereHas('lobby', function ($query) {
                $query->whereIn('status', ['waiting', 'playing']);
            })
            ->pluck('telegram_user_id')
            ->unique()
            ->values()
            ->all();

        $query = TelegramUser::query()
            ->where('telegram_id', '!=', $this->creatorTelegramId);

        if ($activePlayerIds !== []) {
            $query->whereNotIn('id', $activePlayerIds);
        }

        $batchSize = max(1, min($this->count, 500));

        $query->select('id')
            ->chunkById($batchSize, function ($users) use ($lobby) {
                $userIds = $users->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($userIds === []) {
                    return;
                }

                SendLobbyNotificationsBatchJob::dispatch(
                    $lobby->id,
                    $this->creatorTelegramId,
                    $this->creatorName,
                    $userIds
                )->onQueue('telegram');
            });
    }

    private function notificationExists(
        int $lobbyId,
        string $chatId,
        string $chatType
    ): bool {
        return LobbyNotification::query()
            ->where('lobby_id', $lobbyId)
            ->where('chat_id', $chatId)
            ->where('chat_type', $chatType)
            ->exists();
    }

    /**
     * Удаляет уведомления о лобби, которые больше не ожидают игроков.
     */
    private function deleteOldNotifications(
        Api $telegram,
        UserTelegramService $userTelegram
    ): void {
        LobbyNotification::query()
            ->whereHas('lobby', function ($query) {
                $query->where('status', '!=', 'waiting');
            })
            ->chunkById(100, function ($notifications) use (
                $telegram,
                $userTelegram
            ) {
                foreach ($notifications as $notification) {
                    try {
                        if (
                            empty($notification->chat_id) ||
                            empty($notification->telegram_message_id)
                        ) {
                            continue;
                        }

                        if ($notification->chat_type === 'mtproto_group') {
                            $userTelegram->deleteMessage(
                                $notification->chat_id,
                                (int) $notification->telegram_message_id,
                                'supergroup'
                            );
                        } else {
                            $telegram->deleteMessage([
                                'chat_id' => $notification->chat_id,
                                'message_id' => $notification->telegram_message_id,
                            ]);
                        }
                    } catch (Throwable $e) {
                        Log::warning('Не удалось удалить старое уведомление', [
                            'notification_id' => $notification->id,
                            'chat_id' => $notification->chat_id,
                            'chat_type' => $notification->chat_type,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        // Удаляем запись, даже если Telegram отклонил удаление.
                        $notification->delete();
                    }
                }
            });
    }
}
