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
    /**
     * Обрабатывает сообщение глобального чата.
     */
    public function handle($message, Api $telegram): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Определяем тип сообщения
        |--------------------------------------------------------------------------
        */

        $messageType = $this->detectMessageType($message);

        if ($messageType === null) {
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
        |
        | ВАЖНО:
        |
        | Имя берём НАПРЯМУЮ из Telegram.
        | username для отображения НЕ используется.
        |
        */

        $firstName = trim(
            (string) ($message->from->first_name ?? '')
        );

        if ($firstName === '') {
            $firstName = 'Пользователь';
        }

        /*
        |--------------------------------------------------------------------------
        | username / фамилия
        |--------------------------------------------------------------------------
        |
        | username и last_name можно сохранить в БД,
        | но для отображения глобального чата они НЕ используются.
        |
        */

        $username = $message->from->username ?? null;

        $lastName = $message->from->last_name ?? null;

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
                'last_name' => $lastName,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Текст / caption
        |--------------------------------------------------------------------------
        */

        $text = trim(
            (string) ($message->text ?? '')
        );

        /*
         * Для media берём caption.
         */

        if ($messageType !== 'text') {
            $text = trim(
                (string) ($message->caption ?? '')
            );
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
        | Получаем file_id
        |--------------------------------------------------------------------------
        */

        $mediaFileId = $this->getMediaFileId(
            $message,
            $messageType
        );

        /*
        |--------------------------------------------------------------------------
        | Проверяем Reply
        |--------------------------------------------------------------------------
        */

        $replyToTelegramMessageId =
            $message->reply_to_message->message_id
            ?? null;

        $replyToChatMessage = null;

        if ($replyToTelegramMessageId) {
            $replyDelivery =
                ChatMessageDelivery::query()
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
                $replyToChatMessage =
                    ChatMessage::find(
                        $replyDelivery->chat_message_id
                    );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Упоминания
        |--------------------------------------------------------------------------
        |
        | Пока сохраняем старую систему @username для упоминаний
        | внутри текста сообщения.
        |
        | Это НЕ влияет на имя автора.
        |
        */

        $mentionedUsers =
            $this->findMentionedUsers($text);

        /*
        |--------------------------------------------------------------------------
        | Имя автора
        |--------------------------------------------------------------------------
        |
        | ТЕПЕРЬ ТОЛЬКО first_name ИЗ TELEGRAM.
        |
        | Никакого:
        |
        | @username
        |
        */

        $authorName = $firstName;

        /*
        |--------------------------------------------------------------------------
        | Формируем текст и Telegram entities
        |--------------------------------------------------------------------------
        */

        $chatText = '';

        $entities = [];

        /*
        |--------------------------------------------------------------------------
        | Автор
        |--------------------------------------------------------------------------
        */

        $authorStartOffset =
            $this->utf16Length($chatText);

        $chatText .= $authorName;

        $authorLength =
            $this->utf16Length($authorName);

        /*
        |--------------------------------------------------------------------------
        | Кликабельный профиль автора
        |--------------------------------------------------------------------------
        |
        | text_mention позволяет открыть профиль пользователя
        | непосредственно по Telegram ID.
        |
        */

        $entities[] = [
            'type' => 'text_mention',

            'offset' =>
                $authorStartOffset,

            'length' =>
                $authorLength,

            'user' => [
                'id' =>
                    (int) $telegramUserId,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Жирное имя автора
        |--------------------------------------------------------------------------
        */

        $entities[] = [
            'type' => 'bold',

            'offset' =>
                $authorStartOffset,

            'length' =>
                $authorLength,
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

        $chatText .=
            ($user->chat_icon ?? '🟠')
            . ': ';

        /*
        |--------------------------------------------------------------------------
        | Позиция начала пользовательского текста
        |--------------------------------------------------------------------------
        */

        $messageTextStartOffset =
            $this->utf16Length($chatText);

        $chatText .= $text;

        /*
        |--------------------------------------------------------------------------
        | Создаём настоящие Telegram text_mention
        |--------------------------------------------------------------------------
        */

        foreach ($mentionedUsers as $mentionedUser) {
            if (
                !$mentionedUser->username
                || !$mentionedUser->telegram_id
            ) {
                continue;
            }

            $mentionText =
                '@' . $mentionedUser->username;

            /*
            |--------------------------------------------------------------------------
            | Ищем все вхождения username
            |--------------------------------------------------------------------------
            */

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

                /*
                |--------------------------------------------------------------------------
                | Текст до @username
                |--------------------------------------------------------------------------
                */

                $textBeforeMention =
                    substr(
                        $text,
                        0,
                        $byteOffset
                    );

                /*
                |--------------------------------------------------------------------------
                | UTF-16 offset
                |--------------------------------------------------------------------------
                */

                $mentionOffset =
                    $messageTextStartOffset
                    + $this->utf16Length(
                        $textBeforeMention
                    );

                $mentionLength =
                    $this->utf16Length(
                        $matchedText
                    );

                /*
                |--------------------------------------------------------------------------
                | text_mention
                |--------------------------------------------------------------------------
                */

                $entities[] = [
                    'type' =>
                        'text_mention',

                    'offset' =>
                        $mentionOffset,

                    'length' =>
                        $mentionLength,

                    'user' => [
                        'id' =>
                            (int) $mentionedUser->telegram_id,
                    ],
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Reply
        |--------------------------------------------------------------------------
        */

        if ($replyToChatMessage) {
            /*
             * Автор оригинального сообщения.
             */

            $replyAuthor =
                $replyToChatMessage->user;

            if ($replyAuthor) {
                /*
                |--------------------------------------------------------------------------
                | Имя автора Reply
                |--------------------------------------------------------------------------
                |
                | Берём first_name из TelegramUser,
                | который был сохранён при его последнем сообщении.
                |
                | username НЕ используется.
                |
                */

                $replyUsername =
                    trim(
                        (string) (
                            $replyAuthor->first_name
                            ?? ''
                        )
                    );

                if ($replyUsername === '') {
                    $replyUsername = 'Пользователь';
                }

                /*
                |--------------------------------------------------------------------------
                | Текст оригинального сообщения
                |--------------------------------------------------------------------------
                */

                $replyText =
                    $replyToChatMessage->message;

                /*
                |--------------------------------------------------------------------------
                | Если media был без caption
                |--------------------------------------------------------------------------
                */

                if (
                    trim((string) $replyText)
                    === ''
                ) {
                    $replyText =
                        $this->getReplyMediaText(
                            $replyToTelegramMessageId
                        );
                }

                /*
                |--------------------------------------------------------------------------
                | Ограничение цитаты
                |--------------------------------------------------------------------------
                */

                if (
                    mb_strlen(
                        $replyText
                    ) > 200
                ) {
                    $replyText =
                        mb_substr(
                            $replyText,
                            0,
                            200
                        )
                        . '...';
                }

                /*
                |--------------------------------------------------------------------------
                | Формируем Reply block
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
                | Reply должен быть в начале
                |--------------------------------------------------------------------------
                */

                $oldChatText =
                    $chatText;

                $chatText =
                    $replyBlock
                    . $oldChatText;

                /*
                |--------------------------------------------------------------------------
                | Все существующие entities
                | сдвигаем вправо
                |--------------------------------------------------------------------------
                */

                $replyOffset =
                    $this->utf16Length(
                        $replyBlock
                    );

                foreach ($entities as &$entity) {
                    $entity['offset'] +=
                        $replyOffset;
                }

                unset($entity);

                /*
                |--------------------------------------------------------------------------
                | Telegram ID автора Reply
                |--------------------------------------------------------------------------
                */

                $replyAuthorTelegramId =
                    $replyAuthor->telegram_id
                    ?? null;

                /*
                |--------------------------------------------------------------------------
                | Entity автора Reply
                |--------------------------------------------------------------------------
                */

                if ($replyAuthorTelegramId) {
                    $replyAuthorStartOffset =
                        $this->utf16Length(
                            '↩️ '
                        );

                    $replyAuthorLength =
                        $this->utf16Length(
                            $replyUsername
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | Кликабельный профиль Reply автора
                    |--------------------------------------------------------------------------
                    */

                    $entities[] = [
                        'type' =>
                            'text_mention',

                        'offset' =>
                            $replyAuthorStartOffset,

                        'length' =>
                            $replyAuthorLength,

                        'user' => [
                            'id' =>
                                (int) $replyAuthorTelegramId,
                        ],
                    ];

                    /*
                    |--------------------------------------------------------------------------
                    | Жирное имя Reply автора
                    |--------------------------------------------------------------------------
                    */

                    $entities[] = [
                        'type' =>
                            'bold',

                        'offset' =>
                            $replyAuthorStartOffset,

                        'length' =>
                            $replyAuthorLength,
                    ];
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Лог
        |--------------------------------------------------------------------------
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

                'firstName' =>
                    $firstName,

                'messageType' =>
                    $messageType,

                'mediaFileId' =>
                    $mediaFileId,

                'replyToChatMessageId' =>
                    $replyToChatMessage
                        ? $replyToChatMessage->id
                        : null,

                'chatText' =>
                    $chatText,

                'entities' =>
                    $entities,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Отправляем Job
        |--------------------------------------------------------------------------
        */

        GlobalChatDeliveryJob::dispatch(
            chatMessageId:
                $chatMessage->id,

            replyToChatMessageId:
                $replyToChatMessage
                    ? $replyToChatMessage->id
                    : null,

            authorTelegramId:
                (int) $telegramUserId,

            chatText:
                $chatText,

            entities:
                $entities,

            messageType:
                $messageType,

            mediaFileId:
                $mediaFileId,
        )->onQueue('telegram');

        /*
        |--------------------------------------------------------------------------
        | Лог
        |--------------------------------------------------------------------------
        */

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
     * Определяем тип сообщения.
     */
    private function detectMessageType(
        $message
    ): ?string {
        if (!empty($message->text)) {
            return 'text';
        }

        if (!empty($message->sticker)) {
            return 'sticker';
        }

        if (!empty($message->animation)) {
            return 'animation';
        }

        if (!empty($message->voice)) {
            return 'voice';
        }

        if (!empty($message->video)) {
            return 'video';
        }

        if (!empty($message->photo)) {
            return 'photo';
        }

        if (!empty($message->audio)) {
            return 'audio';
        }

        if (!empty($message->document)) {
            return 'document';
        }

        return null;
    }

    /**
     * Получаем file_id.
     */
    private function getMediaFileId(
        $message,
        string $messageType
    ): ?string {
        switch ($messageType) {
            case 'sticker':
                return $message->sticker->file_id
                    ?? null;

            case 'animation':
                return $message->animation->file_id
                    ?? null;

            case 'voice':
                return $message->voice->file_id
                    ?? null;

            case 'video':
                return $message->video->file_id
                    ?? null;

            case 'audio':
                return $message->audio->file_id
                    ?? null;

            case 'document':
                return $message->document->file_id
                    ?? null;

            case 'photo':
                if (!empty($message->photo)) {
                    $photos =
                        $message->photo;

                    $lastPhoto =
                        $photos[
                            count($photos) - 1
                        ];

                    return $lastPhoto->file_id
                        ?? null;
                }

                return null;
        }

        return null;
    }

    /**
     * Находит пользователей,
     * которых упомянули.
     */
    private function findMentionedUsers(
        string $text
    ) {
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
            $usernames[
                strtolower($username)
            ] = $username;
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
     * Возвращает длину строки
     * в UTF-16 code units.
     */
    private function utf16Length(
        string $text
    ): int {
        $utf16 =
            mb_convert_encoding(
                $text,
                'UTF-16LE',
                'UTF-8'
            );

        return intdiv(
            strlen($utf16),
            2
        );
    }

    /**
     * Текст для Reply на media.
     *
     * Сам media уже хранится/отправляется
     * отдельно через Job.
     */
    private function getReplyMediaText(
        ?int $telegramMessageId
    ): string {
        if (!$telegramMessageId) {
            return 'Сообщение';
        }

        return 'Сообщение';
    }
}