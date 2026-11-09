<?php

namespace App\Jobs;

use App\Models\Lobby;
use App\Models\LobbyNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * Не допускаем одновременного обновления уведомлений
     * одного и того же лобби.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("update-lobby-telegram-{$this->lobbyId}"))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function handle(Api $telegram): void
    {
        $lobby = Lobby::with(['clan', 'creator'])
            ->find($this->lobbyId);

        if (!$lobby) {
            Log::warning('Лобби для обновления Telegram не найдено', [
                'lobby_id' => $this->lobbyId,
            ]);

            return;
        }

        $roomCode = strtoupper(trim($lobby->game_room_code ?? ''));

        $players = (int) ($lobby->current_players ?? 0);
        $maxPlayers = (int) ($lobby->max_players ?? 12);

        /**
         * Экранирование HTML для Telegram.
         */
        $escape = static function ($value): string {
            return htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        };

        $safeRoomCode = $escape($roomCode);

        /**
         * Название клана.
         */
        $clanLabel = '';

        if ($lobby->clan) {
            $clanLabel = ' [' . $escape($lobby->clan->name) . ']';
        }

        /**
         * Создатель лобби.
         */
        $hostName = 'Неизвестно';

        if ($lobby->creator) {
            if (!empty($lobby->creator->username)) {
                $hostName = '@' . $lobby->creator->username;
            } elseif (!empty($lobby->creator->first_name)) {
                $hostName = $lobby->creator->first_name;
            }
        }

        $hostName = $escape($hostName);

        /**
         * Карта.
         */
        $mapName = $escape(
            $lobby->map_name ?: 'Неизвестно'
        );

        /**
         * Реальные состояния игры:
         *
         * lobby       -> waiting
         * countdown   -> countdown
         * running     -> started
         * ended       -> finished
         *
         * initializing и prelobby
         * не должны менять статус лобби.
         */
        $status = strtolower(trim($lobby->status ?? 'waiting'));

        $statusMap = [
            'waiting'   => '🟢 ОЖИДАНИЕ ИГРОКОВ',
            'countdown' => '🟡 ИДЁТ ОТСЧЁТ',
            'started'   => '🔥 МАТЧ НАЧАЛСЯ',
            'finished'  => '🏁 МАТЧ ЗАВЕРШЁН',
            'closed'    => '🔴 КОМНАТА ЗАКРЫТА',
        ];

        $statusLabel = $statusMap[$status]
            ?? '⚪ НЕИЗВЕСТНЫЙ СТАТУС';

        /**
         * Ссылка на игровую комнату.
         */
        $gameLink = 'https://play.suspects.io/?code='
            . urlencode($roomCode);

        /**
         * Формируем сообщение.
         */
        $text = "🔔 <b>Лобби #{$lobby->id}{$clanLabel}</b>\n\n"
            . "<b>{$statusLabel}</b>\n\n"
            . "👤 Хост: <b>{$hostName}</b>\n"
            . "🔑 Код: <code>{$safeRoomCode}</code>\n\n"
            . "👥 <b>ИГРОКИ: {$players}/{$maxPlayers}</b>\n\n"
            . "🗺 Карта: {$mapName}\n";

        /**
         * Кнопки доступны во время ожидания
         * и обратного отсчёта.
         *
         * После начала матча кнопки убираются.
         */
        $replyMarkup = null;

        if (in_array($status, ['waiting', 'countdown'], true)) {
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

        /**
         * Получаем существующие уведомления.
         * Новые сообщения здесь НЕ создаются.
         */
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
                    // Удаляем кнопки после начала или завершения матча.
                    $params['reply_markup'] = json_encode([
                        'inline_keyboard' => [],
                    ]);
                }

                $telegram->editMessageText($params);

                $updated++;

            } catch (Throwable $e) {
                $error = mb_strtolower($e->getMessage());

                /**
                 * Telegram сообщает, что сообщение
                 * уже содержит такой же текст и кнопки.
                 */
                if (str_contains($error, 'message is not modified')) {
                    $updated++;

                    continue;
                }

                $failed++;

                Log::warning(
                    'Не удалось обновить Telegram-сообщение',
                    [
                        'lobby_id' => $lobby->id,
                        'notification_id' => $notification->id,
                        'chat_id' => $notification->chat_id,
                        'message_id' => $notification->telegram_message_id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        Log::info('Обновление Telegram-уведомлений завершено', [
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'status' => $status,
            'players' => $players,
            'max_players' => $maxPlayers,
            'notifications_found' => $notifications->count(),
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }
}