<?php

namespace App\Services\Bot;

use App\Models\ChatMessage;
use App\Models\TelegramUser;
use Telegram\Bot\Api;

class GlobalChatHandler
{
    public function handle($message, Api $telegram): bool
    {
        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Данные пользователя из Telegram
        |--------------------------------------------------------------------------
        */

        $telegramUserId = $message->from->id ?? null;

        if (!$telegramUserId) {
            return false;
        }

        $username = $message->from->username ?? null;
        $firstName = $message->from->first_name ?? 'Пользователь';

        /*
        |--------------------------------------------------------------------------
        | Обновляем актуальные данные пользователя
        |--------------------------------------------------------------------------
        */

        $user = TelegramUser::updateOrCreate(
            [
                'telegram_id' => $telegramUserId,
            ],
            [
                'username' => $username,
                'first_name' => $firstName,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Сохраняем сообщение
        |--------------------------------------------------------------------------
        */

        $chatMessage = ChatMessage::create([
            'telegram_user_id' => $user->id,
            'message' => $text,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Имя автора
        |--------------------------------------------------------------------------
        */

        $authorName = $username
            ? '@' . $username
            : $firstName;

        /*
        |--------------------------------------------------------------------------
        | Формируем кликабельное имя
        |--------------------------------------------------------------------------
        |
        | Используем telegram_id.
        |
        | Username может измениться.
        | Telegram ID остаётся постоянным.
        |
        */

        $authorLink = '<a href="tg://user?id=' .
            $telegramUserId .
            '">' .
            htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8') .
            '</a>';

        $chatText =
            $authorLink .
            ":\n" .
            htmlspecialchars(
                $chatMessage->message,
                ENT_QUOTES,
                'UTF-8'
            );

        /*
        |--------------------------------------------------------------------------
        | Рассылаем сообщение всем пользователям
        |--------------------------------------------------------------------------
        */

        $users = TelegramUser::where(
            'telegram_id',
            '!=',
            $telegramUserId
        )->get();

        foreach ($users as $recipient) {
            try {
                $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $chatText,
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Throwable $e) {
                \Log::warning(
                    'Global chat send error',
                    [
                        'telegram_user_id' => $recipient->id,
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }

        return true;
    }
}