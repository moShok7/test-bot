<?php

namespace App\Services\Bot;

use App\Models\ChatMessage;
use App\Models\TelegramUser;
use Telegram\Bot\Api;
use Illuminate\Support\Facades\Log;

class GlobalChatHandler
{
    public function handle($message, Api $telegram): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Текст сообщения
        |--------------------------------------------------------------------------
        */

        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Telegram ID автора
        |--------------------------------------------------------------------------
        */

        $telegramUserId = $message->from->id ?? null;

        if (!$telegramUserId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Данные автора
        |--------------------------------------------------------------------------
        */

        $username = $message->from->username ?? null;

        $firstName = $message->from->first_name
            ?? 'Пользователь';

        /*
        |--------------------------------------------------------------------------
        | Создаём / обновляем пользователя
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
        | Сохраняем оригинальное сообщение
        |--------------------------------------------------------------------------
        |
        | В БД сохраняем именно то, что написал пользователь:
        |
        | Привет @moShok7
        |
        */

        ChatMessage::create([
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
        | Делаем автора кликабельным
        |--------------------------------------------------------------------------
        */

        $authorLink = '<a href="tg://user?id='
            . $telegramUserId
            . '">'
            . htmlspecialchars(
                $authorName,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            . '</a>';

        /*
        |--------------------------------------------------------------------------
        | Преобразуем @username в Telegram mention
        |--------------------------------------------------------------------------
        */

        $messageText = $this->makeMentionsClickable($text);

        /*
        |--------------------------------------------------------------------------
        | Формируем сообщение
        |--------------------------------------------------------------------------
        */

        $chatText =
            $authorLink
            . "\n"
            . ($user->chat_icon ?? '🟠')
            . ': '
            . $messageText;

        /*
        |--------------------------------------------------------------------------
        | Пользователи, которым отправляем сообщение
        |--------------------------------------------------------------------------
        */

        $users = TelegramUser::where(
            'telegram_id',
            '!=',
            $telegramUserId
        )
            ->where('chat_notifications', true)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Рассылка
        |--------------------------------------------------------------------------
        */

        foreach ($users as $recipient) {
            try {
                $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $chatText,
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Throwable $e) {
                Log::warning(
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

    /**
     * Преобразует @username в кликабельный Telegram mention.
     *
     * Например:
     *
     * Привет @moShok7
     *
     * превращается в:
     *
     * Привет <a href="tg://user?id=123456">@moShok7</a>
     */
    private function makeMentionsClickable(string $text): string
    {
        /*
        |--------------------------------------------------------------------------
        | Сначала полностью экранируем HTML
        |--------------------------------------------------------------------------
        */

        $text = htmlspecialchars(
            $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        /*
        |--------------------------------------------------------------------------
        | Находим все @username
        |--------------------------------------------------------------------------
        */

        preg_match_all(
            '/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_]{5,32})/u',
            $text,
            $matches
        );

        if (empty($matches[1])) {
            return $text;
        }

        /*
        |--------------------------------------------------------------------------
        | Убираем дубликаты
        |--------------------------------------------------------------------------
        */

        $usernames = [];

        foreach ($matches[1] as $username) {
            $usernames[strtolower($username)] = $username;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем пользователей в БД
        |--------------------------------------------------------------------------
        */

        $users = TelegramUser::query()
            ->whereNotNull('username')
            ->whereIn(
                \DB::raw('LOWER(username)'),
                array_keys($usernames)
            )
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Заменяем найденные username на Telegram mention
        |--------------------------------------------------------------------------
        */

        foreach ($users as $user) {
            if (
                empty($user->username) ||
                empty($user->telegram_id)
            ) {
                continue;
            }

            $username = $user->username;

            $safeUsername = htmlspecialchars(
                $username,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $mention = '<a href="tg://user?id='
                . $user->telegram_id
                . '">@'
                . $safeUsername
                . '</a>';

            /*
            |--------------------------------------------------------------------------
            | Регистронезависимая замена
            |--------------------------------------------------------------------------
            */

            $pattern = '/(?<![a-zA-Z0-9_])@'
                . preg_quote($username, '/')
                . '(?![a-zA-Z0-9_])/iu';

            $text = preg_replace(
                $pattern,
                $mention,
                $text
            );
        }

        return $text;
    }
}