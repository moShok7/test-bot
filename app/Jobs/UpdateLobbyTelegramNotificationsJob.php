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
            return;
        }

        $players = $lobby->current_players ?? 0;
        $maxPlayers = $lobby->max_players;

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

        $text = "🔔 <b>Лобби #{$lobby->id}{$clanLabel}</b>\n\n"
            . "🔑 Код: <code>{$roomCode}</code>\n"
            . "👥 Игроки: {$players}/{$maxPlayers}\n"
            . "🗺 Карта: {$mapName}\n"
            . "🎯 Режим: {$gameMode}\n\n"
            . '👇 Вход в игру:';

        $notifications = LobbyNotification::query()
            ->where('lobby_id', $lobby->id)
            ->whereIn('chat_type', ['private', 'group'])
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($notifications as $notification) {
            if (
                empty($notification->chat_id) ||
                empty($notification->telegram_message_id)
            ) {
                continue;
            }

            try {
                $telegram->editMessageText([
                    'chat_id' => $notification->chat_id,
                    'message_id' => $notification->telegram_message_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);

                $updated++;
            } catch (Throwable $e) {
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
            'updated' => $updated,
            'failed' => $failed,
        ]);
    }
}
