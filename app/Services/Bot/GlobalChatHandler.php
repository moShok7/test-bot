<?php

namespace App\Services\Bot;

use App\Models\ChatMessage;
use App\Models\ChatMessageDelivery;
use App\Models\ChatMessageMention;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class GlobalChatHandler
{
    /**
     * Обрабатывает сообщение глобального чата.
     */
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
        | Находим mentions
        |--------------------------------------------------------------------------
        |
        | Например:
        |
        | @vasya привет
        | @petya @kolya смотрите
        |
        */

        $mentionedUsers = $this->findMentionedUsers($text);

        /*
        |--------------------------------------------------------------------------
        | Сохраняем mentions в отдельную таблицу
        |--------------------------------------------------------------------------
        */

        foreach ($mentionedUsers as $mentionedUser) {
            ChatMessageMention::firstOrCreate([
                'chat_message_id' => $chatMessage->id,
                'telegram_user_id' => $mentionedUser->id,
            ]);
        }

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
        | Делаем mentions кликабельными
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
        | Если это Reply
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
                | Ограничиваем цитату
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
        | Telegram ID пользователей, которых упомянули
        |--------------------------------------------------------------------------
        */

        $mentionedTelegramIds = $mentionedUsers
            ->pluck('telegram_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Рассылаем сообщение
        |--------------------------------------------------------------------------
        */

        foreach ($recipients as $recipient) {
            try {
                /*
                |--------------------------------------------------------------------------
                | Является ли этот пользователь упомянутым?
                |--------------------------------------------------------------------------
                */

                $isMentioned = in_array(
                    (int) $recipient->telegram_id,
                    $mentionedTelegramIds,
                    true
                );

                /*
                |--------------------------------------------------------------------------
                | Текст для конкретного получателя
                |--------------------------------------------------------------------------
                */

                $recipientText = $chatText;

                if ($isMentioned) {
                    $recipientText =
                        '🔴 <b>Тебя упомянули</b>'
                        . "\n\n"
                        . $recipientText;
                }

                /*
                |--------------------------------------------------------------------------
                | Параметры отправки
                |--------------------------------------------------------------------------
                */

                $sendParams = [
                    'chat_id' => $recipient->telegram_id,
                    'text' => $recipientText,
                    'parse_mode' => 'HTML',
                ];

                /*
                |--------------------------------------------------------------------------
                | Reply к оригинальному Telegram-сообщению
                |--------------------------------------------------------------------------
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
                | Inline-кнопка
                |--------------------------------------------------------------------------
                |
                | Для mention показываем кнопку.
                |
                */

                if ($isMentioned) {
                    $sendParams['reply_markup'] = [
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '🔔 Открыть сообщение',
                                    'callback_data' =>
                                        'chat_message:' . $chatMessage->id,
                                ],
                            ],
                        ],
                    ];
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

        /*
        |--------------------------------------------------------------------------
        | ВАЖНО
        |--------------------------------------------------------------------------
        |
        | Если тебе нужно сохранять delivery самого автора тоже,
        | это можно сделать отдельно.
        |
        */

        return true;
    }

    /**
     * Находит TelegramUser, которых упомянули в тексте.
     */
    private function findMentionedUsers(string $text)
    {
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

        if (empty($matches[1])) {
            return collect();
        }

        /*
        |--------------------------------------------------------------------------
        | Убираем дубли
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

        return TelegramUser::query()
            ->whereNotNull('username')
            ->whereIn(
                DB::raw('LOWER(username)'),
                array_keys($usernames)
            )
            ->get();
    }

    /**
     * Делает @username кликабельным Telegram mention.
     */
    private function makeMentionsClickable(
        string $text
    ): string {
        /*
        |--------------------------------------------------------------------------
        | Сначала экранируем HTML
        |--------------------------------------------------------------------------
        */

        $escapedText = htmlspecialchars(
            $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        /*
        |--------------------------------------------------------------------------
        | Ищем usernames в исходном тексте
        |--------------------------------------------------------------------------
        */

        preg_match_all(
            '/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_]{1,32})(?![a-zA-Z0-9_])/u',
            $text,
            $matches
        );

        if (empty($matches[1])) {
            return $escapedText;
        }

        /*
        |--------------------------------------------------------------------------
        | Уникальные usernames
        |--------------------------------------------------------------------------
        */

        $usernames = [];

        foreach ($matches[1] as $username) {
            $usernames[strtolower($username)] = $username;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем их в БД
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
        | Заменяем @username на tg://user
        |--------------------------------------------------------------------------
        */

        foreach ($users as $mentionedUser) {
            if (
                !$mentionedUser->username ||
                !$mentionedUser->telegram_id
            ) {
                continue;
            }

            $username = $mentionedUser->username;

            $telegramId = $mentionedUser->telegram_id;

            $safeUsername = htmlspecialchars(
                $username,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $mention =
                '<a href="tg://user?id='
                . $telegramId
                . '">@'
                . $safeUsername
                . '</a>';

            /*
            |--------------------------------------------------------------------------
            | Заменяем только конкретный username
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