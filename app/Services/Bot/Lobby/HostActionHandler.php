<?php

namespace App\Services\Bot\Lobby;

use App\Models\TelegramUser;
use App\Models\Lobby;
use App\Models\BotSession;
use App\Models\LobbyPlayer;
use App\Models\LobbyNotification;

class HostActionHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $chatId = $message->chat->id;

        /*
        |--------------------------------------------------------------------------
        | Получаем Telegram-пользователя
        |--------------------------------------------------------------------------
        */

        $telegramUser = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$telegramUser) {
            return false;
        }

        $telegramUserId = $telegramUser->id;

        /*
        |--------------------------------------------------------------------------
        | Главное меню
        |--------------------------------------------------------------------------
        */

        if ($text === '⬅️ Главное меню') {

            BotSession::where(
                'telegram_user_id',
                $telegramUserId
            )->delete();

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' => '🎮 Игровое меню',

                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            [
                                'text' => '➕ Создать лобби'
                            ],
                            [
                                'text' => '🔍 Найти лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '🎮 Моё лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '⚙️ Настройки'
                            ]
                        ]
                    ],

                    'resize_keyboard' => true
                ])
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Удалить лобби
        |--------------------------------------------------------------------------
        */

        if ($text === '❌ Удалить лобби') {

            $lobby = Lobby::where(
                'creator_id',
                $telegramUserId
            )
            ->whereIn(
                'status',
                [
                    'waiting',
                    'playing'
                ]
            )
            ->first();

            if (!$lobby) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,

                    'text' =>
                        '❌ Активное лобби не найдено.'
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Удаляем Telegram-уведомления
            |--------------------------------------------------------------------------
            */

            $notifications = LobbyNotification::where(
                'lobby_id',
                $lobby->id
            )->get();

            foreach ($notifications as $notification) {

                if (
                    $notification->chat_id &&
                    $notification->telegram_message_id
                ) {

                    try {

                        $telegram->deleteMessage([
                            'chat_id' =>
                                $notification->chat_id,

                            'message_id' =>
                                $notification->telegram_message_id
                        ]);

                    } catch (\Throwable $e) {

                        // Сообщение уже могло быть удалено
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Удаляем уведомления из БД
            |--------------------------------------------------------------------------
            */

            LobbyNotification::where(
                'lobby_id',
                $lobby->id
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Удаляем игроков
            |--------------------------------------------------------------------------
            */

            LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Удаляем лобби
            |--------------------------------------------------------------------------
            */

            $lobby->delete();

            /*
            |--------------------------------------------------------------------------
            | Удаляем сессию
            |--------------------------------------------------------------------------
            */

            BotSession::where(
                'telegram_user_id',
                $telegramUserId
            )->delete();

            /*
            |--------------------------------------------------------------------------
            | Возвращаем главное меню
            |--------------------------------------------------------------------------
            */

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    '❌ Лобби успешно удалено.',

                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            [
                                'text' => '➕ Создать лобби'
                            ],
                            [
                                'text' => '🔍 Найти лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '🎮 Моё лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '⚙️ Настройки'
                            ]
                        ]
                    ],

                    'resize_keyboard' => true
                ])
            ]);

            return true;
        }

        return false;
    }
}