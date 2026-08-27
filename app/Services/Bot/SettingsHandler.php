<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;

class SettingsHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $user = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        /*
        |--------------------------------------------------------------------------
        | ⚙️ Настройки
        |--------------------------------------------------------------------------
        */

        if ($text === '⚙️ Настройки') {

            $this->showSettings(
                $message,
                $telegram,
                $user
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 🔔 Уведомления
        |--------------------------------------------------------------------------
        */

        if ($text === '🔔 Уведомления') {

            $this->showNotifications(
                $message,
                $telegram,
                $user
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 💬 Уведомления чата
        |--------------------------------------------------------------------------
        */

        if ($text === '💬 Уведомления чата') {

            $this->showChatNotifications(
                $message,
                $telegram,
                $user
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 🟢 Включить
        |--------------------------------------------------------------------------
        */

        if ($text === '🟢 Включить уведомления') {

            if ($user) {
                $user->update([
                    'chat_notifications' => true,
                ]);
            }

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "💬 Уведомления чата\n\n" .
                    "🟢 Уведомления включены.",
                'reply_markup' => $this->chatNotificationKeyboard(),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 🔴 Выключить
        |--------------------------------------------------------------------------
        */

        if ($text === '🔴 Выключить уведомления') {

            if ($user) {
                $user->update([
                    'chat_notifications' => false,
                ]);
            }

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "💬 Уведомления чата\n\n" .
                    "🔴 Уведомления выключены.",
                'reply_markup' => $this->chatNotificationKeyboard(),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | ⬅️ Назад
        |--------------------------------------------------------------------------
        */

        if ($text === '⬅️ Назад') {

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => "🎮 Игровое меню",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            ['text' => '➕ Создать лобби'],
                            ['text' => '🔍 Найти лобби'],
                        ],
                        [
                            ['text' => '🎮 Моё лобби'],
                        ],
                        [
                            ['text' => '⚙️ Настройки'],
                        ],
                    ],
                    'resize_keyboard' => true,
                ]),
            ]);

            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | ⚙️ Настройки
    |--------------------------------------------------------------------------
    */

    private function showSettings(
        $message,
        $telegram,
        $user
    ): void {

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,

            'text' =>
                "⚙️ Настройки\n\n" .
                "Выберите нужную настройку:",

            'reply_markup' => json_encode([
                'keyboard' => [
                    [
                        ['text' => '🔔 Уведомления'],
                    ],
                    [
                        ['text' => '⬅️ Назад'],
                    ],
                ],
                'resize_keyboard' => true,
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 🔔 Уведомления
    |--------------------------------------------------------------------------
    */

    private function showNotifications(
        $message,
        $telegram,
        $user
    ): void {

        $status = $user && $user->chat_notifications
            ? '🟢 Включены'
            : '🔴 Выключены';

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,

            'text' =>
                "🔔 Уведомления\n\n" .
                "💬 Уведомления чата: {$status}",

            'reply_markup' => json_encode([
                'keyboard' => [
                    [
                        ['text' => '💬 Уведомления чата'],
                    ],
                    [
                        ['text' => '⬅️ Назад'],
                    ],
                ],
                'resize_keyboard' => true,
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 💬 Уведомления чата
    |--------------------------------------------------------------------------
    */

    private function showChatNotifications(
        $message,
        $telegram,
        $user
    ): void {

        $enabled = $user && $user->chat_notifications;

        $status = $enabled
            ? '🟢 Включены'
            : '🔴 Выключены';

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,

            'text' =>
                "💬 Уведомления чата\n\n" .
                "Статус: {$status}\n\n" .
                "Получать уведомления о новых сообщениях в чате?",

            'reply_markup' => $this->chatNotificationKeyboard(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Кнопки уведомлений
    |--------------------------------------------------------------------------
    */

    private function chatNotificationKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [
                    ['text' => '🟢 Включить уведомления'],
                ],
                [
                    ['text' => '🔴 Выключить уведомления'],
                ],
                [
                    ['text' => '⬅️ Назад'],
                ],
            ],
            'resize_keyboard' => true,
        ]);
    }
}