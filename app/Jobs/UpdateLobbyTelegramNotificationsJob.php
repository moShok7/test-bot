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

        // Статус комнаты.
        $status = strtolower(trim($lobby->status ?? 'waiting'));

        $statusMap = [
            'waiting' => '🟢 ОЖИДАНИЕ ИГРОКОВ',
            'started' => '🔥 МАТЧ НАЧАЛСЯ',
            'finished' => '🏁 МАТЧ ЗАВЕРШЁН',
            'closed' => '🔴 КОМНАТА ЗАКРЫТА',
        ];

        $statusLabel = $statusMap[$status]
            ?? '⚪ НЕИЗВЕСТНЫЙ СТАТУС';

        // Ссылка на комнату.
        $gameLink = 'https://play.suspects.io/?code=' . urlencode($roomCode);

        // Формируем сообщение.
        $text = "🔔 <b>Лобби #{$lobby->id}{$clanLabel}</b>\n\n"
            . "<b>{$statusLabel}</b>\n\n"
            . "🔑 Код: <code>{$safeRoomCode}</code>\n"
            . "👥 Игроки: {$players}/{$maxPlayers}\n"
            . "🗺 Карта: {$mapName}\n".

        // Кнопки зависят от статуса.
        $replyMarkup = null;

        if (in_array($status, ['waiting', 'started'], true)) {
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

            $text .= "\n👇 <b>Для входа в игру нажми кнопку ниже:</b>";
        }

        // Получаем отправленные уведомления этого лобби.
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
                $params = [
                    'chat_id' => $notification->chat_id,
                    'message_id' => $notification->telegram_message_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ];

                if ($replyMarkup !== null) {
                    $params['reply_markup'] = json_encode(
                        $replyMarkup,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                } else {
                    // Убираем старые кнопки после завершения или закрытия.
                    $params['reply_markup'] = json_encode([
                        'inline_keyboard' => [],
                    ]);
                }

                $telegram->editMessageText($params);

                $updated++;

            } catch (Throwable $e) {
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
            'status' => $status,
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }
}
