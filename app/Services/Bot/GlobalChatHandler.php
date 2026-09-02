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
    /**
     * Обрабатывает сообщение глобального чата.
     */
    public function handle($message, Api $telegram): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Текст
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
        | Находим упомянутых пользователей
        |--------------------------------------------------------------------------
        */

        $mentionedUsers = $this->findMentionedUsers($text);

        /*
        |--------------------------------------------------------------------------
        | Имя автора
        |--------------------------------------------------------------------------
        */

        $authorName = $username
            ? '@' . $username
            : $firstName;

        $safeAuthorName = htmlspecialchars(
            $authorName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        /*
        |--------------------------------------------------------------------------
        | Автор как Telegram mention
        |--------------------------------------------------------------------------
        */

        $authorLink =
            '<a href="tg://user?id='
            . $telegramUserId
            . '">'
            . $safeAuthorName
            . '</a>';

        /*
        |--------------------------------------------------------------------------
        | Текст с кликабельными mentions
        |--------------------------------------------------------------------------
        */

        $messageText = $this->makeMentionsClickable($text);

        /*
        |--------------------------------------------------------------------------
        | Основной текст
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
        | Если сообщение является Reply
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
                | Ограничение цитаты
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
        | Получаем получателей
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
        | Telegram ID упомянутых пользователей
        |--------------------------------------------------------------------------
        */

        $mentionedTelegramIds = $mentionedUsers
            ->pluck('telegram_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Рассылка
        |--------------------------------------------------------------------------
        */

        foreach ($recipients as $recipient) {
            try {
                /*
                |--------------------------------------------------------------------------
                | Это упомянутый пользователь?
                |--------------------------------------------------------------------------
                */

                $isMentioned = in_array(
                    (int) $recipient->telegram_id,
                    $mentionedTelegramIds,
                    true
                );

                /*
                |--------------------------------------------------------------------------
                | Параметры основного сообщения
                |--------------------------------------------------------------------------
                */

                $sendParams = [
                    'chat_id' => $recipient->telegram_id,
                    'text' => $chatText,
                    'parse_mode' => 'HTML',
                ];

                /*
                |--------------------------------------------------------------------------
                | Если это Reply, отвечаем на оригинальную
                | копию сообщения именно этого пользователя.
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
                | Отправляем основное сообщение
                |--------------------------------------------------------------------------
                */

                $sentMessage = $telegram->sendMessage(
                    $sendParams
                );

                /*
                |--------------------------------------------------------------------------
                | Telegram message_id основной копии
                |--------------------------------------------------------------------------
                */

                $sentTelegramMessageId =
                    $sentMessage->getMessageId();

                /*
                |--------------------------------------------------------------------------
                | Сохраняем delivery
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

                /*
                |--------------------------------------------------------------------------
                | Если пользователя упомянули,
                | отправляем отдельное уведомление.
                |--------------------------------------------------------------------------
                */

                if ($isMentioned) {
                    $notificationParams = [
                        'chat_id' => $recipient->telegram_id,

                        'text' =>
                            '🔴 <b>Тебя упомянули</b>',

                        'parse_mode' => 'HTML',

                        'reply_parameters' => [
                            'message_id' =>
                                $sentTelegramMessageId,
                        ],
                    ];

                    $telegram->sendMessage(
                        $notificationParams
                    );
                }

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

                        'trace' =>
                            $e->getTraceAsString(),
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Находит пользователей, которых упомянули.
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
        | Уникальные usernames
        |--------------------------------------------------------------------------
        */

        $usernames = [];

        foreach ($matches[1] as $username) {
            $usernames[strtolower($username)] = $username;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем пользователей
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
     * Делает @username кликабельным.
     */
    private function makeMentionsClickable(
        string $text
    ): string {
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
        | Ищем usernames
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
        | Получаем пользователей
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
        | Делаем Telegram mentions
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