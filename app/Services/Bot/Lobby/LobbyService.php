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
        | Получаем уведомления этого лобби
        |--------------------------------------------------------------------------
        */

        $notifications = LobbyNotification::where(
            'lobby_id',
            $lobby->id
        )->get();

        /*
        |--------------------------------------------------------------------------
        | Удаляем сообщения уведомлений из Telegram
        |--------------------------------------------------------------------------
        */

        foreach ($notifications as $notification) {

            if (
                empty($notification->chat_id) ||
                empty($notification->telegram_message_id)
            ) {
                continue;
            }

            try {

                /*
                |--------------------------------------------------------------------------
                | Если это группа, сначала открепляем сообщение
                |--------------------------------------------------------------------------
                */

                if ($notification->chat_type === 'group') {

                    try {

                        $this->telegram->unpinChatMessage([
                            'chat_id' => $notification->chat_id,
                            'message_id' => $notification->telegram_message_id,
                        ]);

                        \Log::info(
                            'Уведомление откреплено',
                            [
                                'lobby_id' => $lobby->id,
                                'chat_id' => $notification->chat_id,
                                'message_id' => $notification->telegram_message_id,
                            ]
                        );

                    } catch (\Throwable $e) {

                        /*
                        | Если сообщение уже не закреплено,
                        | это не критическая ошибка.
                        */

                        \Log::warning(
                            'Не удалось открепить уведомление',
                            [
                                'lobby_id' => $lobby->id,
                                'chat_id' => $notification->chat_id,
                                'message_id' => $notification->telegram_message_id,
                                'error' => $e->getMessage(),
                            ]
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Удаляем сообщение
                |--------------------------------------------------------------------------
                */

                $this->telegram->deleteMessage([
                    'chat_id' => $notification->chat_id,
                    'message_id' => $notification->telegram_message_id,
                ]);

                \Log::info(
                    'Уведомление о лобби удалено из Telegram',
                    [
                        'lobby_id' => $lobby->id,
                        'chat_id' => $notification->chat_id,
                        'chat_type' => $notification->chat_type,
                        'message_id' => $notification->telegram_message_id,
                    ]
                );

            } catch (\Throwable $e) {

                \Log::warning(
                    'Не удалось удалить уведомление о новом лобби',
                    [
                        'lobby_id' => $lobby->id,
                        'chat_id' => $notification->chat_id,
                        'chat_type' => $notification->chat_type,
                        'message_id' => $notification->telegram_message_id,
                        'error' => $e->getMessage(),
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

            $text = "❌ Лобби #{$lobby->id} было закрыто.";

            if ($reason) {
                $text .= "\n\nПричина: {$reason}";
            }

            try {

                $this->telegram->sendMessage([
                    'chat_id' => $player->telegramUser->telegram_id,
                    'text' => $text,
                ]);

            } catch (\Throwable $e) {

                \Log::warning(
                    'Не удалось отправить уведомление об удалении лобби',
                    [
                        'lobby_id' => $lobby->id,
                        'telegram_id' => $player->telegramUser->telegram_id,
                        'error' => $e->getMessage(),
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
                now()->subMinutes(77)
            )
            ->get();

        foreach ($lobbies as $lobby) {

            $this->delete(
                $lobby,
                'Лобби неактивно более 77 минут.'
            );
        }
    }
}