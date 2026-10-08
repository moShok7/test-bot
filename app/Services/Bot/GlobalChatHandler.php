<?php

namespace App\Services\Bot;

use App\Jobs\GlobalChatDeliveryBatchJob;
use App\Models\BotGroup;
use App\Models\ChatMessage;
use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class GlobalChatHandler
{
    /**
     * Обработка сообщений глобального чата.
     */
    public function handle($message, Api $telegram): bool
    {
        try {
            $chat = $message->getChat();
            $chatId = (int) $chat->getId();
            $chatType = $chat->getType();

            /*
             * Группы и супергруппы здесь только регистрируем.
             * Сам глобальный чат работает через личку.
             */
            if ($chatType === 'group' || $chatType === 'supergroup') {
                try {
                    BotGroup::updateOrCreate(
                        ['chat_id' => $chatId],
                        [
                            'title' => $chat->getTitle(),
                            'type' => $chatType,
                            'is_active' => true,
                        ]
                    );
                } catch (Throwable $e) {
                    Log::warning('GlobalChat: failed to register group', [
                        'chat_id' => $chatId,
                        'error' => $e->getMessage(),
                    ]);
                }

                return false;
            }

            if ($chatType !== 'private') {
                return false;
            }

            /*
             * Определяем тип сообщения.
             */
            $messageType = $this->detectMessageType($message);

            /*
             * Если это неподдерживаемый тип, ничего не делаем.
             */
            if ($messageType === null) {
                return false;
            }

            /*
             * Данные автора.
             */
            $from = $message->getFrom();

            if (!$from) {
                return false;
            }

            $telegramUserId = (int) $from->getId();

            $username = $from->getUsername();
            $firstName = trim((string) ($from->getFirstName() ?? ''));
            $lastName = trim((string) ($from->getLastName() ?? ''));

            /*
             * Сохраняем / обновляем пользователя.
             */
            $telegramUser = TelegramUser::updateOrCreate(
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
             * Текст сообщения.
             */
            $text = $message->getText();

            if (!$text) {
                $text = $message->getCaption();
            }

            $text = (string) ($text ?? '');

            /*
             * ID файла, если сообщение содержит медиа.
             */
            $mediaFileId = $this->getMediaFileId($message, $messageType);

            /*
             * Сохраняем сообщение в БД.
             */
            $chatMessage = ChatMessage::create([
                'telegram_user_id' => $telegramUser->id,
                'telegram_message_id' => (int) $message->getMessageId(),
                'message_type' => $messageType,
                'text' => $text,
                'file_id' => $mediaFileId,
            ]);

            /*
             * ============================================================
             * REPLY
             * ============================================================
             */

            $replyToChatMessage = null;

            $replyMessage = $message->getReplyToMessage();

            if ($replyMessage) {
                $replyTelegramMessageId = (int) $replyMessage->getMessageId();

                /*
                 * Ищем сообщение, на которое пользователь ответил.
                 * Telegram ID сообщения у каждого пользователя свой,
                 * поэтому ищем через ChatMessageDelivery.
                 */
                $delivery = ChatMessageDelivery::where(
                    'telegram_message_id',
                    $replyTelegramMessageId
                )
                    ->where('telegram_user_id', $telegramUserId)
                    ->first();

                if ($delivery) {
                    $replyToChatMessage = ChatMessage::find($delivery->chat_message_id);
                }
            }

            /*
             * ============================================================
             * СОБИРАЕМ ТЕКСТ ГЛОБАЛЬНОГО ЧАТА
             * ============================================================
             */

            $chatText = '';
            $entities = [];

            /*
             * ------------------------------------------------------------
             * REPLY БЛОК
             * ------------------------------------------------------------
             */

            if ($replyToChatMessage) {
                $replyAuthor = TelegramUser::find($replyToChatMessage->telegram_user_id);

                if ($replyAuthor) {
                    /*
                     * Только имя.
                     * Никакого @username.
                     */
                    $replyAuthorName = trim((string) $replyAuthor->first_name);

                    if ($replyAuthorName === '') {
                        $replyAuthorName = 'Пользователь';
                    }

                    $replyText = trim(
                        (string) (
                            $replyToChatMessage->text
                            ?: $this->getReplyMediaText($replyToChatMessage)
                        )
                    );

                    if ($replyText === '') {
                        $replyText = 'Сообщение';
                    }

                    /*
                     * Сохраняем старый текст до добавления текущего
                     * сообщения, чтобы правильно рассчитать offset.
                     */
                    $replyPrefix = '↩️ ';

                    $replyAuthorStartOffset = $this->utf16Length(
                        $replyPrefix
                    );

                    $replyAuthorLength = $this->utf16Length(
                        $replyAuthorName
                    );

                    $replyBlock =
                        $replyPrefix .
                        $replyAuthorName .
                        "\n" .
                        $replyText .
                        "\n\n";

                    $chatText .= $replyBlock;

                    /*
                     * Имя автора reply делаем кликабельным.
                     */
                    $entities[] = [
                        'type' => 'text_link',
                        'offset' => $replyAuthorStartOffset,
                        'length' => $replyAuthorLength,
                        'url' => 'tg://openmessage?user_id=' .
                            (int) $replyAuthor->telegram_id,
                    ];

                    /*
                     * Имя автора reply жирное.
                     */
                    $entities[] = [
                        'type' => 'bold',
                        'offset' => $replyAuthorStartOffset,
                        'length' => $replyAuthorLength,
                    ];
                }
            }

            /*
             * ============================================================
             * АВТОР
             * ============================================================
             */

            /*
             * Только имя.
             *
             * Например:
             * Иван
             *
             * НЕ:
             * @ivan
             */
            $authorName = $firstName;

            if ($authorName === '') {
                $authorName = 'Пользователь';
            }

            /*
             * Если перед автором уже есть reply-блок,
             * offset должен учитывать его длину.
             */
            $authorStartOffset = $this->utf16Length($chatText);

            $chatText .= $authorName;

            $authorLength = $this->utf16Length($authorName);

            /*
             * Имя автора кликабельное.
             */
            $entities[] = [
                'type' => 'text_link',
                'offset' => $authorStartOffset,
                'length' => $authorLength,
                'url' => 'tg://openmessage?user_id=' .
                    (int) $telegramUserId,
            ];

            /*
             * Имя автора жирное.
             */
            $entities[] = [
                'type' => 'bold',
                'offset' => $authorStartOffset,
                'length' => $authorLength,
            ];

            /*
             * ============================================================
             * ИКОНКА + ТЕКСТ
             * ============================================================
             */

            $chatText .= "\n";

            $icon = $telegramUser->chat_icon ?? '🟠';

            $chatText .= $icon . ': ';

            /*
             * Offset текста пользователя.
             */
            $textStartOffset = $this->utf16Length($chatText);

            $chatText .= $text;

            /*
             * ============================================================
             * УПОМИНАНИЯ
             * ============================================================
             *
             * Если пользователь написал:
             *
             * Привет @Ivan
             *
             * В глобальном чате будет:
             *
             * Иван
             *
             * без @, если мы заменяем сам текст.
             *
             * Но здесь мы сохраняем исходный текст пользователя
             * и делаем @Ivan кликабельным.
             *
             * Если хочешь именно отображение "Ivan" без @,
             * это можно сделать отдельной заменой текста.
             */

            if ($text !== '') {
                $mentionedUsers = $this->findMentionedUsers($text);

                foreach ($mentionedUsers as $mention) {
                    $mentionedUser = $mention['user'];

                    $mentionStartInText = $mention['offset'];
                    $mentionLength = $mention['length'];

                    /*
                     * Переводим offset из PHP-строк в UTF-16 offset,
                     * который использует Telegram Bot API.
                     */
                    $beforeMention = mb_substr(
                        $text,
                        0,
                        $mentionStartInText,
                        'UTF-8'
                    );

                    $mentionValue = mb_substr(
                        $text,
                        $mentionStartInText,
                        $mentionLength,
                        'UTF-8'
                    );

                    $mentionOffset =
                        $textStartOffset +
                        $this->utf16Length($beforeMention);

                    $telegramMentionLength =
                        $this->utf16Length($mentionValue);

                    /*
                     * Делаем @username кликабельным.
                     */
                    $entities[] = [
                        'type' => 'text_link',
                        'offset' => $mentionOffset,
                        'length' => $telegramMentionLength,
                        'url' => 'tg://openmessage?user_id=' .
                            (int) $mentionedUser->telegram_id,
                    ];
                }
            }

            /*
             * ============================================================
             * СОХРАНЯЕМ / ОТПРАВЛЯЕМ
             * ============================================================
             */

            GlobalChatDeliveryBatchJob::dispatch(
                chatMessageId: $chatMessage->id,
                replyToChatMessageId: $replyToChatMessage
                    ? $replyToChatMessage->id
                    : null,
                authorTelegramId: $telegramUserId,
                chatText: $chatText,
                entities: $entities,
                messageType: $messageType,
                mediaFileId: $mediaFileId,
            )->onQueue('telegram');

            return true;
        } catch (Throwable $e) {
            Log::error('GlobalChatHandler error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * Определяем тип Telegram-сообщения.
     */
    private function detectMessageType($message): ?string
    {
        if ($message->getText() !== null) {
            return 'text';
        }

        if ($message->getSticker() !== null) {
            return 'sticker';
        }

        if ($message->getAnimation() !== null) {
            return 'animation';
        }

        if ($message->getVoice() !== null) {
            return 'voice';
        }

        if ($message->getVideo() !== null) {
            return 'video';
        }

        if ($message->getPhoto() !== null) {
            return 'photo';
        }

        if ($message->getAudio() !== null) {
            return 'audio';
        }

        if ($message->getDocument() !== null) {
            return 'document';
        }

        /*
         * Сообщения с caption тоже поддерживаем.
         */
        if ($message->getCaption() !== null) {
            if ($message->getAnimation() !== null) {
                return 'animation';
            }

            if ($message->getVideo() !== null) {
                return 'video';
            }

            if ($message->getPhoto() !== null) {
                return 'photo';
            }

            if ($message->getAudio() !== null) {
                return 'audio';
            }

            if ($message->getDocument() !== null) {
                return 'document';
            }
        }

        return null;
    }

    /**
     * Получаем file_id для медиа.
     */
    private function getMediaFileId($message, ?string $messageType): ?string
    {
        if (!$messageType) {
            return null;
        }

        switch ($messageType) {
            case 'sticker':
                $sticker = $message->getSticker();

                return $sticker
                    ? $sticker->getFileId()
                    : null;

            case 'animation':
                $animation = $message->getAnimation();

                return $animation
                    ? $animation->getFileId()
                    : null;

            case 'voice':
                $voice = $message->getVoice();

                return $voice
                    ? $voice->getFileId()
                    : null;

            case 'video':
                $video = $message->getVideo();

                return $video
                    ? $video->getFileId()
                    : null;

            case 'audio':
                $audio = $message->getAudio();

                return $audio
                    ? $audio->getFileId()
                    : null;

            case 'document':
                $document = $message->getDocument();

                return $document
                    ? $document->getFileId()
                    : null;

            case 'photo':
                $photos = $message->getPhoto();

                if (!$photos || count($photos) === 0) {
                    return null;
                }

                /*
                 * Берём самое большое фото.
                 */
                $largestPhoto = null;
                $largestSize = 0;

                foreach ($photos as $photo) {
                    $fileSize = (int) ($photo->getFileSize() ?? 0);

                    if ($fileSize >= $largestSize) {
                        $largestSize = $fileSize;
                        $largestPhoto = $photo;
                    }
                }

                if ($largestPhoto) {
                    return $largestPhoto->getFileId();
                }

                /*
                 * Fallback.
                 */
                $lastPhoto = end($photos);

                return $lastPhoto
                    ? $lastPhoto->getFileId()
                    : null;
        }

        return null;
    }

    /**
     * Находим пользователей, которых упомянули через @username.
     *
     * Возвращает:
     *
     * [
     *     [
     *         'user' => TelegramUser,
     *         'offset' => 10,
     *         'length' => 6,
     *     ]
     * ]
     */
    private function findMentionedUsers(string $text): array
    {
        preg_match_all(
            '/@([a-zA-Z0-9_]{5,32})/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if (empty($matches[1])) {
            return [];
        }

        $result = [];

        foreach ($matches[1] as $match) {
            $username = $match[0];

            /*
             * Позиция @ в байтах.
             */
            $byteOffset = $match[1];

            /*
             * Получаем количество UTF-8 символов до @.
             */
            $before = substr($text, 0, $byteOffset);

            $characterOffset = mb_strlen(
                $before,
                'UTF-8'
            );

            /*
             * Ищем пользователя без @.
             */
            $user = TelegramUser::whereRaw(
                'LOWER(username) = ?',
                [mb_strtolower($username, 'UTF-8')]
            )->first();

            if (!$user) {
                continue;
            }

            /*
             * Длина username + @.
             */
            $mentionLength = mb_strlen(
                '@' . $username,
                'UTF-8'
            );

            $result[] = [
                'user' => $user,
                'offset' => $characterOffset,
                'length' => $mentionLength,
            ];
        }

        return $result;
    }

    /**
     * UTF-16 длина строки.
     *
     * Telegram Bot API использует UTF-16 offsets.
     */
    private function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return strlen(
            mb_convert_encoding(
                $text,
                'UTF-16LE',
                'UTF-8'
            )
        ) / 2;
    }

    /**
     * Текст для reply на медиа.
     */
    private function getReplyMediaText(ChatMessage $message): string
    {
        return match ($message->message_type) {
            'sticker' => 'Стикер',
            'animation' => 'GIF',
            'voice' => 'Голосовое сообщение',
            'video' => 'Видео',
            'photo' => 'Фото',
            'audio' => 'Аудио',
            'document' => 'Документ',
            default => 'Сообщение',
        };
    }
}