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
        | Получаем имя автора
        |--------------------------------------------------------------------------
        */

        $authorName = $username
            ? '@' . $username
            : $firstName;

        /*
        |--------------------------------------------------------------------------
        | Формируем текст сообщения и Telegram entities
        |--------------------------------------------------------------------------
        */

        $chatText = '';

        $entities = [];

        /*
        |--------------------------------------------------------------------------
        | Автор
        |--------------------------------------------------------------------------
        */

        $authorStartOffset = $this->utf16Length($chatText);

        $chatText .= $authorName;

        $entities[] = [
            'type' => 'text_mention',
            'offset' => $authorStartOffset,
            'length' => $this->utf16Length($authorName),
            'user' => [
                'id' => (int) $telegramUserId,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Новая строка
        |--------------------------------------------------------------------------
        */

        $chatText .= "\n";

        /*
        |--------------------------------------------------------------------------
        | Иконка + сообщение
        |--------------------------------------------------------------------------
        */

        $chatText .= ($user->chat_icon ?? '🟠') . ': ';

        /*
        |--------------------------------------------------------------------------
        | Позиция начала пользовательского текста
        |--------------------------------------------------------------------------
        */

        $messageTextStartOffset = $this->utf16Length($chatText);

        $chatText .= $text;

        /*
        |--------------------------------------------------------------------------
        | Создаём настоящие Telegram text_mention
        |--------------------------------------------------------------------------
        */

        foreach ($mentionedUsers as $mentionedUser) {
            if (
                !$mentionedUser->username ||
                !$mentionedUser->telegram_id
            ) {
                continue;
            }

            $mentionText = '@' . $mentionedUser->username;

            /*
            |--------------------------------------------------------------------------
            | Ищем все вхождения username
            |--------------------------------------------------------------------------
            */

            preg_match_all(
                '/(?<![a-zA-Z0-9_])@'
                . preg_quote($mentionedUser->username, '/')
                . '(?![a-zA-Z0-9_])/iu',
                $text,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            if (empty($matches[0])) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $matchedText = $match[0];
                $byteOffset = $match[1];

                /*
                |--------------------------------------------------------------------------
                | Текст до @username
                |--------------------------------------------------------------------------
                */

                $textBeforeMention = substr(
                    $text,
                    0,
                    $byteOffset
                );

                /*
                |--------------------------------------------------------------------------
                | Telegram использует UTF-16 offsets
                |--------------------------------------------------------------------------
                */

                $mentionOffset =
                    $messageTextStartOffset
                    + $this->utf16Length($textBeforeMention);

                $mentionLength =
                    $this->utf16Length($matchedText);

                /*
                |--------------------------------------------------------------------------
                | Добавляем настоящий text_mention
                |--------------------------------------------------------------------------
                */

                $entities[] = [
                    'type' => 'text_mention',
                    'offset' => $mentionOffset,
                    'length' => $mentionLength,
                    'user' => [
                        'id' => (int) $mentionedUser->telegram_id,
                    ],
                ];
            }
        }

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

                $replyText = $replyToChatMessage->message;

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

                /*
                |--------------------------------------------------------------------------
                | Формируем Reply блок
                |--------------------------------------------------------------------------
                */

                $replyBlock =
                    '↩️ '
                    . $replyUsername
                    . "\n"
                    . $replyText
                    . "\n\n";

                /*
                |--------------------------------------------------------------------------
                | Reply должен быть в начале сообщения
                |--------------------------------------------------------------------------
                */

                $oldChatText = $chatText;

                $chatText = $replyBlock . $oldChatText;

                /*
                |--------------------------------------------------------------------------
                | Все существующие entities нужно сместить
                |--------------------------------------------------------------------------
                */

                $replyOffset = $this->utf16Length(
                    $replyBlock
                );

                foreach ($entities as &$entity) {
                    $entity['offset'] += $replyOffset;
                }

                unset($entity);

                /*
                |--------------------------------------------------------------------------
                | Entity автора Reply
                |--------------------------------------------------------------------------
                */

                $replyAuthorStartOffset =
                    $this->utf16Length('↩️ ');

                $entities[] = [
                    'type' => 'text_mention',
                    'offset' => $replyAuthorStartOffset,
                    'length' => $this->utf16Length(
                        $replyUsername
                    ),
                    'user' => [
                        'id' => (int) $replyAuthor->telegram_id,
                    ],
                ];
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
        | Рассылка
        |--------------------------------------------------------------------------
        */

        foreach ($recipients as $recipient) {
            try {
                /*
                |--------------------------------------------------------------------------
                | Параметры сообщения
                |--------------------------------------------------------------------------
                */

                $sendParams = [
                    'chat_id' => $recipient->telegram_id,
                    'text' => $chatText,
                    'entities' => $entities,
                ];

                /*
                |--------------------------------------------------------------------------
                | Если это Reply
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
                | Отправляем сообщение
                |--------------------------------------------------------------------------
                */

                $sentMessage = $telegram->sendMessage(
                    $sendParams
                );

                /*
                |--------------------------------------------------------------------------
                | Telegram message_id
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
     * Возвращает длину строки в UTF-16 code units.
     *
     * Telegram Bot API использует UTF-16 для offset/length
     * своих MessageEntity.
     */
    private function utf16Length(string $text): int
    {
        $utf16 = mb_convert_encoding(
            $text,
            'UTF-16LE',
            'UTF-8'
        );

        return intdiv(
            strlen($utf16),
            2
        );
    }
}