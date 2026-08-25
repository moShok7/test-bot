<?php

namespace App\Services\Bot\Lobby;

use App\Models\Lobby;
use App\Models\LobbyNotification;
use Telegram\Bot\Api;

class LobbyService
{
    public function __construct(
        protected Api $telegram
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Удаление лобби
    |--------------------------------------------------------------------------
    */

    public function delete(Lobby $lobby, string $reason = ''): void
    {
        /*
        |--------------------------------------------------------------------------
        | Получаем игроков ДО удаления
        |--------------------------------------------------------------------------
        */

        $players = $lobby
            ->players()
            ->with('telegramUser')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Удаляем уведомления "Новое лобби!"
        |--------------------------------------------------------------------------
        */

        $notifications = LobbyNotification::where(
            'lobby_id',
            $lobby->id
        )
        ->with('telegramUser')
        ->get();

        foreach ($notifications as $notification) {

            if (!$notification->telegramUser) {
                continue;
            }

            try {

                $this->telegram->deleteMessage([
                    'chat_id' =>
                        $notification->telegramUser->telegram_id,

                    'message_id' =>
                        $notification->telegram_message_id,
                ]);

            } catch (\Throwable $e) {

                \Log::warning(
                    'Не удалось удалить уведомление о новом лобби',
                    [
                        'lobby_id' =>
                            $lobby->id,

                        'telegram_id' =>
                            $notification->telegramUser->telegram_id,

                        'message_id' =>
                            $notification->telegram_message_id,

                        'error' =>
                            $e->getMessage()
                    ]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Удаляем записи уведомлений из БД
        |--------------------------------------------------------------------------
        */

        LobbyNotification::where(
            'lobby_id',
            $lobby->id
        )->delete();

        /*
        |--------------------------------------------------------------------------
        | Уведомляем игроков о закрытии лобби
        |--------------------------------------------------------------------------
        */

        foreach ($players as $player) {

            if (!$player->telegramUser) {
                continue;
            }

            $text =
                "❌ Лобби #{$lobby->id} было закрыто.";

            if ($reason) {

                $text .=
                    "\n\nПричина: {$reason}";
            }

            try {

                $this->telegram->sendMessage([
                    'chat_id' =>
                        $player->telegramUser->telegram_id,

                    'text' =>
                        $text,
                ]);

            } catch (\Throwable $e) {

                \Log::warning(
                    'Не удалось отправить уведомление об удалении лобби',
                    [
                        'lobby_id' =>
                            $lobby->id,

                        'telegram_id' =>
                            $player->telegramUser->telegram_id,

                        'error' =>
                            $e->getMessage()
                    ]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Удаляем всех игроков
        |--------------------------------------------------------------------------
        */

        $lobby->players()->delete();

        /*
        |--------------------------------------------------------------------------
        | Удаляем лобби
        |--------------------------------------------------------------------------
        */

        $lobby->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Удаление неактивных лобби
    |--------------------------------------------------------------------------
    */

    public function deleteExpiredWaitingLobbies(): void
    {
        $lobbies = Lobby::where(
            'status',
            'waiting'
        )
        ->where(
            'updated_at',
            '<=',
            now()->subMinutes(90)
        )
        ->get();

        foreach ($lobbies as $lobby) {

            $this->delete(
                $lobby,
                'Лобби неактивно более 90 минут.'
            );
        }
    }
}