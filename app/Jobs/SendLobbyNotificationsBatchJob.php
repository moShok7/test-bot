<?php

namespace App\Jobs;

use App\Models\Lobby;
use App\Models\LobbyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class UpdateLobbyTelegramNotificationsJob implements ShouldQueue
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

    public function __construct(
        public int $lobbyId
    ) {
        $this->onQueue('telegram');
    }

    public function handle(Api $telegram): void
    {
        $lobby = Lobby::with('clan')->find($this->lobbyId);

        if (!$lobby) {
            Log::warning('Лобби для обновления уведомлений не найдено', [
                'lobby_id' => $this->lobbyId,
            ]);

            return;
        }

        $players = $lobby->current_players ?? 0;
        $maxPlayers = $lobby->max_players ?? '?';

        $roomCode = strtoupper(trim($lobby->game_room_code ?? ''));

        $safeRoomCode = htmlspecialchars(
            $roomCode,
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

        $mapName = htmlspecialchars(
            $lobby->map_name ?: 'Неизвестно',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $gameMode = htmlspecialchars(
            $lobby->game_mode ?: 'Неизвестно',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // Ссылка для входа в конкретное лобби
        $gameLink = 'https://play.suspects.io/?code=' . urlencode($roomCode);

        // Текст уведомления
        $text = "🔔 <b>Лобби #{$lobby->id}{$clanLabel}</b>\n\n"
            . "🔑 Код: <code>{$safeRoomCode}</code>\n"
            . "👥 Игроки: {$players}/{$maxPlayers}\n"
            . "🗺 Карта: {$mapName}\n"
            . "👇 <b>Для входа в игру нажми кнопку ниже:</b>";

        // Кнопки Telegram
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🎮 Войти в лобби',
                        'url' => $gameLink,
                    ],
                ],
                [
                    [
                        'text' => '📋 Скопировать код',
                        'copy_text' => [
                            'text' => $roomCode,
                        ],
                    ],
                ],
            ],
        ];

        // Получаем все отправленные уведомления этого лобби
        $notifications = LobbyNotification::query()
            ->where('lobby_id', $lobby->id)
            ->whereIn('chat_type', ['private', 'group'])
            ->get();

        $updated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($notifications as $notification) {
            if (
                empty($notification->chat_id) ||
                empty($notification->telegram_message_id)
            ) {
                $skipped++;
                continue;
            }

            try {
                $telegram->editMessageText([
                    'chat_id' => $notification->chat_id,
                    'message_id' => $notification->telegram_message_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(
                        $replyMarkup,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);

                $updated++;

            } catch (Throwable $e) {
                // Telegram возвращает ошибку, если сообщение уже имеет такой текст.
                // Это не считается критической ошибкой.
                if (
                    str_contains(
                        mb_strtolower($e->getMessage()),
                        'message is not modified'
                    )
                ) {
                    $updated++;
                    continue;
                }

                $failed++;

                Log::warning('Не удалось обновить Telegram-уведомление', [
                    'lobby_id' => $lobby->id,
                    'notification_id' => $notification->id,
                    'chat_id' => $notification->chat_id,
                    'message_id' => $notification->telegram_message_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Обновление Telegram-уведомлений завершено', [
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }
}