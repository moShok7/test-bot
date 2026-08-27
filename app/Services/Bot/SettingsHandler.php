<?php

namespace App\Services\Bot;

class SettingsHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        /*
        |--------------------------------------------------------------------------
        | ⚙️ Настройки
        |--------------------------------------------------------------------------
        */

        if ($text === '⚙️ Настройки') {

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,

                'text' =>
                    "⚙️ Настройки\n\n" .
                    "Выберите, что хотите изменить:",

                'reply_markup' => json_encode([
                    'keyboard' => [

                        [
                            [
                                'text' => '🔔 Уведомления'
                            ]
                        ],

                        [
                            [
                                'text' => '⬅️ Назад'
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
        | 🔔 Уведомления
        |--------------------------------------------------------------------------
        */

        if ($text === '🔔 Уведомления') {

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,

                'text' =>
                    "🔔 Уведомления\n\n" .
                    "Здесь можно будет настроить уведомления о сообщениях в чате.",

                'reply_markup' => json_encode([
                    'keyboard' => [

                        [
                            [
                                'text' => '💬 Уведомления чата'
                            ]
                        ],

                        [
                            [
                                'text' => '⬅️ Назад'
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
        | 💬 Уведомления чата
        |--------------------------------------------------------------------------
        */

        if ($text === '💬 Уведомления чата') {

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,

                'text' =>
                    "💬 Уведомления чата\n\n" .
                    "Настройка уведомлений о новых сообщениях в чате.",

                'reply_markup' => json_encode([
                    'keyboard' => [

                        [
                            [
                                'text' => '🟢 Включены'
                            ],

                            [
                                'text' => '🔴 Выключены'
                            ]
                        ],

                        [
                            [
                                'text' => '⬅️ Назад'
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