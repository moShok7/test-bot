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
        | 🎨 Режим выбора иконки
        |--------------------------------------------------------------------------
        |
        | Этот блок должен находиться ДО обработки обычных кнопок.
        | Тогда отправленный emoji не попадёт в GlobalChatHandler.
        |
        */

        if ($user && $user->chat_icon_selection) {

            /*
            |--------------------------------------------------------------------------
            | ❌ Отмена
            |--------------------------------------------------------------------------
            */

            if ($text === '❌ Отмена') {

                $user->update([
                    'chat_icon_selection' => false,
                ]);

                $this->showProfile(
                    $message,
                    $telegram,
                    $user
                );

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Проверяем emoji
            |--------------------------------------------------------------------------
            */

            if ($this->isValidEmoji($text)) {

                $user->update([
                    'chat_icon' => $text,
                    'chat_icon_selection' => false,
                ]);

                /*
                | Обновляем модель после сохранения
                */

                $user->refresh();

                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,

                    'text' =>
                        "✅ Иконка изменена\n\n" .
                        "Теперь твоя иконка: {$user->chat_icon}",

                    'reply_markup' => $this->profileKeyboard(),
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Неверный emoji
            |--------------------------------------------------------------------------
            */

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,

                'text' =>
                    "❌ Не удалось распознать emoji.\n\n" .
                    "Отправь один emoji.\n\n" .
                    "Например: 🔥",

                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            ['text' => '❌ Отмена'],
                        ],
                    ],
                    'resize_keyboard' => true,
                ]),
            ]);

            /*
            | Очень важно:
            | сообщение полностью обработано,
            | поэтому GlobalChatHandler его не получит.
            */

            return true;
        }

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
        | 👤 Профиль
        |--------------------------------------------------------------------------
        */

        if ($text === '👤 Профиль') {

            $this->showProfile(
                $message,
                $telegram,
                $user
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 🎨 Изменить иконку
        |--------------------------------------------------------------------------
        */

        if ($text === '🎨 Изменить иконку') {

            if (!$user) {
                return true;
            }

            /*
            | Включаем режим выбора иконки
            */

            $user->update([
                'chat_icon_selection' => true,
            ]);

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,

                'text' =>
                    "🎨 Новая иконка\n\n" .
                    "Отправь мне любой emoji, который хочешь " .
                    "использовать как свою иконку.\n\n" .
                    "Например: 🔥",

                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            ['text' => '❌ Отмена'],
                        ],
                    ],
                    'resize_keyboard' => true,
                ]),
            ]);

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
        | 🟢 Включить уведомления
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

                'reply_markup' =>
                    $this->chatNotificationKeyboard(),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 🔴 Выключить уведомления
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

                'reply_markup' =>
                    $this->chatNotificationKeyboard(),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | ❌ Отмена
        |--------------------------------------------------------------------------
        */

        if ($text === '❌ Отмена') {

            $this->showProfile(
                $message,
                $telegram,
                $user
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | ⬅️ Назад
        |--------------------------------------------------------------------------
        */

        if ($text === '⬅️ Назад') {

            $this->showMainMenu(
                $message,
                $telegram
            );

            return true;
        }

        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | 👤 Профиль
    |--------------------------------------------------------------------------
    */

    private function showProfile(
        $message,
        $telegram,
        $user
    ): void {

        if (!$user) {
            return;
        }

        $name = $user->username
            ? '@' . $user->username
            : ($user->first_name ?? 'Пользователь');

        $icon = $user->chat_icon ?: '🟠';

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,

            'text' =>
                "👤 Профиль\n\n" .
                "Имя: {$name}\n" .
                "Иконка: {$icon}",

            'reply_markup' =>
                $this->profileKeyboard(),
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | 🎨 Проверка emoji
    |--------------------------------------------------------------------------
    */

    private function isValidEmoji(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        /*
        | Не разрешаем отправлять обычный текст.
        */

        if (mb_strlen($text) > 8) {
            return false;
        }

        /*
        | Emoji Unicode.
        |
        | Поддерживает обычные emoji:
        | 🔥 ❤️ 😂 🟠 👑 💀 🚀
        |
        */

        return preg_match(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u',
            $text
        ) === 1;
    }


    /*
    |--------------------------------------------------------------------------
    | Кнопки профиля
    |--------------------------------------------------------------------------
    */

    private function profileKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [
                    ['text' => '🎨 Изменить иконку'],
                ],
                [
                    ['text' => '⬅️ Назад'],
                ],
            ],
            'resize_keyboard' => true,
        ]);
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
                        ['text' => '👤 Профиль'],
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

            'reply_markup' =>
                $this->chatNotificationKeyboard(),
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


    /*
    |--------------------------------------------------------------------------
    | 🎮 Главное меню
    |--------------------------------------------------------------------------
    */

    private function showMainMenu(
        $message,
        $telegram
    ): void {

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
                        ['text' => '👤 Профиль'],
                    ],
                    [
                        ['text' => '⚙️ Настройки'],
                    ],
                ],
                'resize_keyboard' => true,
            ]),
        ]);
    }
}

