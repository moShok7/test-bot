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
        // Получаем актуальные данные.
        $lobby = Lobby::with(['clan', 'creator'])
            ->find($this->lobbyId);

        if (!$lobby) {
            Log::warning('Лобби для обновления Telegram не найдено', [
                'lobby_id' => $this->lobbyId,
            ]);

            return;
        }

        $escape = static function ($value): string {
            return htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        };

        $roomCode = strtoupper(trim($lobby->game_room_code ?? ''));
        $safeRoomCode = $escape($roomCode);

        $playersCount = (int) ($lobby->current_players ?? 0);
        $maxPlayers = (int) ($lobby->max_players ?? 12);

        // Хост.
        $hostName = 'Неизвестно';

        if ($lobby->creator) {
            if (!empty($lobby->creator->username)) {
                $hostName = '@' . $lobby->creator->username;
            } elseif (!empty($lobby->creator->first_name)) {
                $hostName = $lobby->creator->first_name;
            }
        }

        $hostName = $escape($hostName);

        // Клан.
        $clanLabel = '';

        if ($lobby->clan) {
            $clanLabel = ' 🛡 ' . $escape($lobby->clan->name);
        }

        // Карта и режим.
        $mapName = $escape($lobby->map_name ?: 'Неизвестно');
        $gameMode = $escape($lobby->game_mode ?: 'Неизвестно');

        // Статус.
        $status = strtolower(trim($lobby->status ?? 'waiting'));

        $statusMap = [
            'waiting' => '🟢 ОЖИДАНИЕ ИГРОКОВ',
            'countdown' => '🟡 ИДЁТ ОТСЧЁТ',
            'started' => '🔥 МАТЧ НАЧАЛСЯ',
            'finished' => '🏁 МАТЧ ЗАВЕРШЁН',
            'closed' => '🔴 КОМНАТА ЗАКРЫТА',
        ];

        $statusLabel = $statusMap[$status]
            ?? '⚪ НЕИЗВЕСТНЫЙ СТАТУС';

        // Получаем состав игроков.
        $roster = $lobby->roster ?? [];

        if (is_string($roster)) {
            $decoded = json_decode($roster, true);
            $roster = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($roster)) {
            $roster = [];
        }

        // Убираем некорректные записи.
        $roster = array_values(array_filter(
            $roster,
            fn ($player) => is_array($player)
        ));

        // Формируем список игроков.
        $rosterText = '';

        if (!empty($roster)) {
            foreach ($roster as $index => $player) {
                $name = trim((string) ($player['name'] ?? 'Неизвестно'));
                $character = trim((string) ($player['character'] ?? ''));

                $rosterText .= "\n"
                    . '<b>' . ($index + 1) . '.</b> '
                    . '👤 ' . $escape($name);

                if ($character !== '') {
                    $rosterText .= ' <i>['
                        . $escape($character)
                        . ']</i>';
                }
            }
        } else {
            $rosterText = "\n<i>Список игроков пока недоступен</i>";
        }

        // Ссылка на игру.
        $gameLink = 'https://play.suspects.io/?code='
            . urlencode($roomCode);

        // Сообщение.
        $text =
            "🎮 <b>ИГРОВОЕ ЛОББИ #{$lobby->id}</b>{$clanLabel}\n"
            . "━━━━━━━━━━━━━━━━━━\n\n"

            . "{$statusLabel}\n\n"

            . "👑 <b>ХОСТ</b>\n"
            . "└ {$hostName}\n\n"

            . "🔑 <b>КОД КОМНАТЫ</b>\n"
            . "└ <code>{$safeRoomCode}</code>\n\n"

            . "👥 <b>ИГРОКИ: {$playersCount}/{$maxPlayers}</b>\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "<b>СОСТАВ КОМНАТЫ</b>"
            . $rosterText
            . "\n\n"

            . "━━━━━━━━━━━━━━━━━━\n"
            . "🗺 <b>КАРТА:</b> {$mapName}\n";

        // Кнопки.
        $keyboardRows = [];

        if (in_array($status, ['waiting', 'countdown'], true)) {
            $keyboardRows[] = [
                [
                    'text' => '🎮 Войти в лобби',
                    'url' => $gameLink,
                ],
            ];

            $keyboardRows[] = [
                [
                    'text' => '📋 Скопировать код',
                    'copy_text' => [
                        'text' => $roomCode,
                    ],
                ],
            ];
        }

        $replyMarkup = [
            'inline_keyboard' => $keyboardRows,
        ];

        // Получаем сообщения для редактирования.
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
                        JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                    ),
                ]);

                $updated++;

            } catch (Throwable $e) {
                $error = mb_strtolower($e->getMessage());

                if (str_contains($error, 'message is not modified')) {
                    $updated++;
                    continue;
                }

                $failed++;

                Log::warning('Не удалось обновить Telegram-сообщение', [
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
            'players' => $playersCount,
            'roster_count' => count($roster),
            'roster' => $roster,
            'notifications_found' => $notifications->count(),
            'updated' => $updated,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }
}
