<?php

namespace App\Services\Bot;

use App\Jobs\GlobalChatDeliveryJob;
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
        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        $telegramUserId = $message->from->id ?? null;

        if (!$telegramUserId) {
            return false;
        }

        /*
         * ---------------------------------------------------------
         * 1. Данные автора
         * ---------------------------------------------------------
         */

        $username = $message->from->username ?? null;

        $firstName = trim(
            $message->from->first_name ?? ''
        );

        $lastName = trim(
            $message->from->last_name ?? ''
        );

        if ($username) {
            $authorName = '@' . $username;
        } else {
            $authorName = trim(
                $firstName . ' ' . $lastName
            );

            if ($authorName === '') {
                $authorName = 'Пользователь';
            }
        }

        /*
         * ---------------------------------------------------------
         * 2. Создаём/обновляем пользователя
         * ---------------------------------------------------------
         */

        $user = TelegramUser::updateOrCreate(
            [
                'telegram_id' => $telegramUserId,
            ],
            [
                'username' => $username,
                'first_name' => $firstName !== ''
                    ? $firstName
                    : 'Пользователь',
            ]
        );

        /*
         * ---------------------------------------------------------
         * 3. Сохраняем исходное сообщение
         * ---------------------------------------------------------
         */

        $chatMessage = ChatMessage::create([
            'telegram_user_id' => $user->id,
            'message' => $text,
        ]);

        /*
         * ---------------------------------------------------------
         * 4. Проверяем наш кастомный Reply
         * ---------------------------------------------------------
         *
         * Пользователь отвечает на сообщение бота.
         *
         * Telegram присылает:
         *
         * reply_to_message.message_id
         *
         * Мы ищем этот Telegram message_id в ChatMessageDelivery.
         *
         * В результате получаем наш ChatMessage.
         */

        $replyToChatMessageId = null;

        $replyText = null;
        $replyAuthorName = null;
        $replyAuthorTelegramId = null;

        $replyToMessage = $message->reply_to_message ?? null;

        if ($replyToMessage) {
            $replyTelegramMessageId =
                $replyToMessage->message_id
                ?? null;

            if ($replyTelegramMessageId) {
                $delivery = ChatMessageDelivery::query()
                    ->where(
                        'telegram_user_id',
                        $user->id
                    )
                    ->where(
                        'telegram_message_id',
                        $replyTelegramMessageId
                    )
                    ->first();

                if ($delivery) {
                    $originalChatMessage =
                        ChatMessage::query()
                            ->find($delivery->chat_message_id);

                    if ($originalChatMessage) {
                        $replyToChatMessageId =
                            $originalChatMessage->id;

                        $replyText =
                            $originalChatMessage->message;

                        $replyAuthor =
                            TelegramUser::query()
                                ->find(
                                    $originalChatMessage
                                        ->telegram_user_id
                                );

                        if ($replyAuthor) {
                            $replyAuthorTelegramId =
                                (int) $replyAuthor->telegram_id;

                            if ($replyAuthor->username) {
                                $replyAuthorName =
                                    '@' . $replyAuthor->username;
                            } else {
                                $replyAuthorName = trim(
                                    ($replyAuthor->first_name ?? '')
                                    . ' '
                                    . ($replyAuthor->last_name ?? '')
                                );

                                if ($replyAuthorName === '') {
                                    $replyAuthorName =
                                        'Пользователь';
                                }
                            }
                        }

                        Log::info(
                            'GLOBAL CHAT CUSTOM REPLY FOUND',
                            [
                                'currentTelegramUserId' =>
                                    $telegramUserId,

                                'replyTelegramMessageId' =>
                                    $replyTelegramMessageId,

                                'originalChatMessageId' =>
                                    $replyToChatMessageId,

                                'replyAuthorTelegramId' =>
                                    $replyAuthorTelegramId,
                            ]
                        );
                    }
                }
            }
        }

        /*
         * ---------------------------------------------------------
         * 5. Ищем @username внутри нового сообщения
         * ---------------------------------------------------------
         */

        $mentionedUsers =
            $this->findMentionedUsers($text);

        /*
         * ---------------------------------------------------------
         * 6. Собираем сообщение
         * ---------------------------------------------------------
         *
         * Пример:
         *
         * ↩️ Ответ @ivan
         * ┌ Старое сообщение
         *
         * @petr
         * 🟠: Новое сообщение
         *
         * Автор и @username получают text_mention.
         * ---------------------------------------------------------
         */

        $chatText = '';
        $entities = [];

        /*
         * ----- Reply block -----
         */

        if (
            $replyToChatMessageId
            && $replyText !== null
        ) {
            $chatText .= "↩️ ";

            if (
                $replyAuthorTelegramId
                && $replyAuthorName
            ) {
                $replyAuthorOffset =
                    $this->utf16Length($chatText);

                $chatText .= $replyAuthorName;

                $entities[] = [
                    'type' => 'text_mention',
                    'offset' => $replyAuthorOffset,
                    'length' =>
                        $this->utf16Length($replyAuthorName),
                    'user' => [
                        'id' => $replyAuthorTelegramId,
                    ],
                ];
            } else {
                $chatText .= 'Ответ';
            }

            $chatText .= "\n";

            $quotedText = $this->makeQuote(
                $replyText
            );

            $chatText .= $quotedText;
            $chatText .= "\n\n";
        }

        /*
         * ----- Автор -----
         */

        $authorStartOffset =
            $this->utf16Length($chatText);

        $chatText .= $authorName;

        $entities[] = [
            'type' => 'text_mention',
            'offset' => $authorStartOffset,
            'length' =>
                $this->utf16Length($authorName),
            'user' => [
                'id' => (int) $telegramUserId,
            ],
        ];

        $chatText .= "\n";

        /*
         * ----- Иконка пользователя -----
         */

        $chatText .= ($user->chat_icon ?? '🟠') . ': ';

        /*
         * ----- Основной текст -----
         */

        $messageTextStartOffset =
            $this->utf16Length($chatText);

        $chatText .= $text;

        /*
         * ---------------------------------------------------------
         * 7. Добавляем text_mention для @username
         * ---------------------------------------------------------
         */

        foreach ($mentionedUsers as $mentionedUser) {
            if (
                !$mentionedUser->username
                || !$mentionedUser->telegram_id
            ) {
                continue;
            }

            preg_match_all(
                '/(?<![a-zA-Z0-9_])@'
                . preg_quote(
                    $mentionedUser->username,
                    '/'
                )
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

                $textBeforeMention = substr(
                    $text,
                    0,
                    $byteOffset
                );

                $mentionOffset =
                    $messageTextStartOffset
                    + $this->utf16Length(
                        $textBeforeMention
                    );

                $mentionLength =
                    $this->utf16Length(
                        $matchedText
                    );

                $entities[] = [
                    'type' => 'text_mention',
                    'offset' => $mentionOffset,
                    'length' => $mentionLength,
                    'user' => [
                        'id' =>
                            (int) $mentionedUser->telegram_id,
                    ],
                ];
            }
        }

        /*
         * ---------------------------------------------------------
         * 8. Логи
         * ---------------------------------------------------------
         */

        Log::info(
            'BEFORE GlobalChatDeliveryJob dispatch',
            [
                'chatMessageId' =>
                    $chatMessage->id,

                'authorTelegramId' =>
                    $telegramUserId,

                'authorName' =>
                    $authorName,

                'username' =>
                    $username,

                'replyToChatMessageId' =>
                    $replyToChatMessageId,

                'chatText' =>
                    $chatText,

                'entities' =>
                    $entities,
            ]
        );

        /*
         * ---------------------------------------------------------
         * 9. Отправляем Job
         * ---------------------------------------------------------
         */

        GlobalChatDeliveryJob::dispatch(
            chatMessageId: $chatMessage->id,
            replyToChatMessageId: $replyToChatMessageId,
            authorTelegramId: (int) $telegramUserId,
            chatText: $chatText,
            entities: $entities,
        )->onQueue('telegram');

        Log::info(
            'AFTER GlobalChatDeliveryJob dispatch',
            [
                'chatMessageId' =>
                    $chatMessage->id,
            ]
        );

        return true;
    }

    /**
     * Ищем пользователей, упомянутых через @username.
     */
    private function findMentionedUsers(string $text)
    {
        preg_match_all(
            '/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_]{1,32})(?![a-zA-Z0-9_])/u',
            $text,
            $matches
        );

        if (empty($matches[1])) {
            return collect();
        }

        $usernames = [];

        foreach ($matches[1] as $username) {
            $usernames[strtolower($username)] =
                $username;
        }

        return TelegramUser::query()
            ->whereNotNull('username')
            ->whereIn(
                DB::raw('LOWER(username)'),
                array_keys($usernames)
            )
            ->get();
    }

    /**
     * Делаем красивую цитату для нашего Reply.
     */
    private function makeQuote(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '└ Сообщение';
        }

        $lines = preg_split(
            "/\r\n|\r|\n/",
            $text
        );

        $quotedLines = [];

        foreach ($lines as $line) {
            $quotedLines[] = '└ ' . $line;
        }

        return implode("\n", $quotedLines);
    }

    /**
     * Telegram offsets считаются в UTF-16 code units,
     * а PHP работает со строкой в UTF-8.
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

