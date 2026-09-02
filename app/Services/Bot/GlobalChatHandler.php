<?php

namespace App\Services\Bot;

use App\Models\ChatMessage;
use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

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
        | Проверяем Reply
        |--------------------------------------------------------------------------
        */

        $replyToTelegramMessageId =
            $message->reply_to_message->message_id ?? null;

        $replyToChatMessage = null;

        if ($replyToTelegramMessageId) {

            /*
            |--------------------------------------------------------------------------
            | Ищем, какому глобальному сообщению соответствует
            | сообщение, на которое пользователь ответил
            |--------------------------------------------------------------------------
            */

            $replyDelivery = ChatMessageDelivery::query()
                ->where(
                    'telegram_user_id',
                    $user->id
                )
                ->where(
                    'telegram_message_id',
                    $replyToTelegramMessageId
                )
                ->first();

            if ($replyDelivery) {
                $replyToChatMessage = ChatMessage::find(
                    $replyDelivery->chat_message_id
                );
            }
        }

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
        | Автор кликабельный
        |--------------------------------------------------------------------------
        */

        $authorLink =
            '<a href="tg://user?id='
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
        | Обрабатываем mentions
        |--------------------------------------------------------------------------
        */

        $messageText = $this->makeMentionsClickable($text);

        /*
        |--------------------------------------------------------------------------
        | Формируем основное сообщение
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
        | Если это ответ, добавляем информацию об оригинале
        |--------------------------------------------------------------------------
        */

        if ($replyToChatMessage) {

            $replyAuthor = $replyToChatMessage->user;

            if ($replyAuthor) {

                $replyUsername = $replyAuthor->username
                    ? '@' . $replyAuthor->username
                    : $replyAuthor->first_name;

                $replyAuthorName = htmlspecialchars(
                    $replyUsername,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );

                $replyText = htmlspecialchars(
                    $replyToChatMessage->message,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );

                /*
                |--------------------------------------------------------------------------
                | Ограничиваем длину цитаты
                |--------------------------------------------------------------------------
                */

                if (mb_strlen($replyText) > 200) {
                    $replyText = mb_substr(
                        $replyText,
                        0,
                        200
                    ) . '...';
                }

                $replyBlock =
                    '↩️ <b>'
                    . $replyAuthorName
                    . '</b>'
                    . "\n"
                    . '<i>'
                    . $replyText
                    . '</i>'
                    . "\n\n";

                $chatText =
                    $replyBlock
                    . $chatText;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем пользователей
        |--------------------------------------------------------------------------
        */

        $recipients = TelegramUser::query()
            ->where(
                'telegram_id',
                '!=',
                $telegramUserId
            )
            ->where(
                'chat_notifications',
                true
            )
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Рассылаем сообщение
        |--------------------------------------------------------------------------
        */

        foreach ($recipients as $recipient) {

            try {

                /*
                |--------------------------------------------------------------------------
                | Параметры отправки
                |--------------------------------------------------------------------------
                */

                $sendParams = [
                    'chat_id' => $recipient->telegram_id,
                    'text' => $chatText,
                    'parse_mode' => 'HTML',
                ];

                /*
                |--------------------------------------------------------------------------
                | Если это Reply
                |--------------------------------------------------------------------------
                |
                | Находим именно Telegram message_id оригинала
                | у ЭТОГО получателя.
                |
                */

                if ($replyToChatMessage) {

                    $originalDelivery =
                        ChatMessageDelivery::query()
                            ->where(
                                'chat_message_id',
                                $replyToChatMessage->id
                            )
                            ->where(
                                'telegram_user_id',
                                $recipient->id
                            )
                            ->first();

                    if ($originalDelivery) {

                        $sendParams['reply_parameters'] = [
                            'message_id' =>
                                $originalDelivery->telegram_message_id,
                        ];
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Отправляем
                |--------------------------------------------------------------------------
                */

                $sentMessage = $telegram->sendMessage(
                    $sendParams
                );

                /*
                |--------------------------------------------------------------------------
                | Получаем Telegram message_id
                |--------------------------------------------------------------------------
                */

                $sentTelegramMessageId =
                    $sentMessage->getMessageId();

                /*
                |--------------------------------------------------------------------------
                | Сохраняем связь:
                |
                | ChatMessage
                |      ↓
                | Recipient
                |      ↓
                | Telegram message_id
                |--------------------------------------------------------------------------
                */

                ChatMessageDelivery::updateOrCreate(
                    [
                        'chat_message_id' =>
                            $chatMessage->id,

                        'telegram_user_id' =>
                            $recipient->id,
                    ],
                    [
                        'telegram_message_id' =>
                            $sentTelegramMessageId,
                    ]
                );

            } catch (\Throwable $e) {

                Log::warning(
                    'Global chat send error',
                    [
                        'telegram_user_id' =>
                            $recipient->id,

                        'chat_message_id' =>
                            $chatMessage->id,

                        'message' =>
                            $e->getMessage(),
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Делает @username кликабельным Telegram mention.
     */
    private function makeMentionsClickable(
        string $text
    ): string {

        /*
        |--------------------------------------------------------------------------
        | Ищем @username
        |--------------------------------------------------------------------------
        */

        preg_match_all(
            '/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_]{1,32})(?![a-zA-Z0-9_])/u',
            $text,
            $matches
        );

        /*
        |--------------------------------------------------------------------------
        | Экранируем HTML
        |--------------------------------------------------------------------------
        */

        $escapedText = htmlspecialchars(
            $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        /*
        |--------------------------------------------------------------------------
        | Нет mentions
        |--------------------------------------------------------------------------
        */

        if (empty($matches[1])) {
            return $escapedText;
        }

        /*
        |--------------------------------------------------------------------------
        | Username из сообщения
        |--------------------------------------------------------------------------
        */

        $usernames = [];

        foreach ($matches[1] as $username) {

            $usernames[strtolower($username)] =
                $username;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем пользователей
        |--------------------------------------------------------------------------
        */

        $users = TelegramUser::query()
            ->whereNotNull('username')
            ->whereIn(
                DB::raw('LOWER(username)'),
                array_keys($usernames)
            )
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Карта username => Telegram ID
        |--------------------------------------------------------------------------
        */

        foreach ($users as $mentionedUser) {

            if (
                !$mentionedUser->username ||
                !$mentionedUser->telegram_id
            ) {
                continue;
            }

            $username =
                $mentionedUser->username;

            $telegramId =
                $mentionedUser->telegram_id;

            $safeUsername =
                htmlspecialchars(
                    $username,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );

            /*
            |--------------------------------------------------------------------------
            | Настоящий Telegram mention
            |--------------------------------------------------------------------------
            */

            $mention =
                '<a href="tg://user?id='
                . $telegramId
                . '">@'
                . $safeUsername
                . '</a>';

            /*
            |--------------------------------------------------------------------------
            | Заменяем username
            |--------------------------------------------------------------------------
            */

            $pattern =
                '/(?<![a-zA-Z0-9_])@'
                . preg_quote($username, '/')
                . '(?![a-zA-Z0-9_])/iu';

            $escapedText = preg_replace(
                $pattern,
                $mention,
                $escapedText
            );
        }

        return $escapedText;
    }
}