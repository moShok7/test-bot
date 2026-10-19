<?php

namespace App\Services\Bot;

use App\Jobs\GlobalChatDeliveryJob;
use App\Models\BotGroup;
use App\Models\ChatMessage;
use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
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

            if (!$chat) {
                return false;
            }

            $chatId = (int) $chat->getId();
            $chatType = $chat->getType();

            /*
            |--------------------------------------------------------------------------
            | Регистрация групп
            |--------------------------------------------------------------------------
            */

            if (
                $chatType === 'group'
                || $chatType === 'supergroup'
            ) {
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
                    Log::warning(
                        'GlobalChat: failed to register group',
                        [
                            'chat_id' => $chatId,
                            'error' => $e->getMessage(),
                        ]
                    );
                }

                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Глобальный чат работает только в личных сообщениях
            |--------------------------------------------------------------------------
            */

            if ($chatType !== 'private') {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Тип сообщения
            |--------------------------------------------------------------------------
            */

            $messageType = $this->detectMessageType($message);

            if ($messageType === null) {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Автор
            |--------------------------------------------------------------------------
            */

            $from = $message->getFrom();

            if (!$from) {
                return false;
            }

            $telegramUserId = (int) $from->getId();

            $firstName = trim(
                (string) ($from->getFirstName() ?? '')
            );

            $lastName = trim(
                (string) ($from->getLastName() ?? '')
            );

            $username = $from->getUsername();

            /*
            |--------------------------------------------------------------------------
            | Пользователь
            |--------------------------------------------------------------------------
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
            |--------------------------------------------------------------------------
            | Текст или подпись к медиа
            |--------------------------------------------------------------------------
            */

            $text = $message->getText();

            if ($text === null) {
                $text = $message->getCaption();
            }

            $text = (string) ($text ?? '');

            /*
            |--------------------------------------------------------------------------
            | Идентификатор медиа
            |--------------------------------------------------------------------------
            */

            $mediaFileId = $this->getMediaFileId(
                $message,
                $messageType
            );

            /*
            |--------------------------------------------------------------------------
            | Reply: ищем исходное сообщение по delivery
            |--------------------------------------------------------------------------
            */

            $replyToChatMessage = null;

            $replyMessage = $message->getReplyToMessage();

            if ($replyMessage) {
                $replyTelegramMessageId = (int)
                    $replyMessage->getMessageId();

                $delivery = ChatMessageDelivery::query()
                    ->where(
                        'telegram_message_id',
                        $replyTelegramMessageId
                    )
                    ->where(
                        'telegram_user_id',
                        $telegramUser->id
                    )
                    ->first();

                if ($delivery) {
                    $replyToChatMessage = ChatMessage::find(
                        $delivery->chat_message_id
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Сохраняем сообщение в БД
            |--------------------------------------------------------------------------
            */

            $storedMessage = $text !== ''
                ? $text
                : '[' . $messageType . ']';

            $chatMessage = ChatMessage::create([
                'telegram_user_id' => $telegramUser->id,
                'message' => $storedMessage,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Собираем сообщение для рассылки
            |--------------------------------------------------------------------------
            */

            $chatText = '';
            $entities = [];

            /*
            |--------------------------------------------------------------------------
            | Блок ответа
            |--------------------------------------------------------------------------
            */

            if ($replyToChatMessage) {
                $replyAuthor = TelegramUser::find(
                    $replyToChatMessage->telegram_user_id
                );

                if ($replyAuthor) {
                    $replyAuthorName = trim(
                        (string) $replyAuthor->first_name
                        . ' '
                        . (string) $replyAuthor->last_name
                    );

                    if ($replyAuthorName === '') {
                        $replyAuthorName = 'Пользователь';
                    }

                    $replyText = trim(
                        (string) $replyToChatMessage->message
                    );

                    if ($replyText === '') {
                        $replyText = 'Сообщение';
                    }

                    $replyPrefix = '↩️ ';

                    $authorOffset = $this->utf16Length(
                        $replyPrefix
                    );

                    $authorLength = $this->utf16Length(
                        $replyAuthorName
                    );

                    $chatText .=
                        $replyPrefix
                        . $replyAuthorName
                        . "\n"
                        . $replyText
                        . "\n\n";

                    $entities[] = [
                        'type' => 'text_link',
                        'offset' => $authorOffset,
                        'length' => $authorLength,
                        'url' => 'tg://user?id='
                            . (int) $replyAuthor->telegram_id,
                    ];

                    $entities[] = [
                        'type' => 'bold',
                        'offset' => $authorOffset,
                        'length' => $authorLength,
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Имя автора — кликабельная ссылка
            |--------------------------------------------------------------------------
            */

            $authorName = trim(
                $firstName . ' ' . $lastName
            );

            if ($authorName === '') {
                $authorName = 'Пользователь';
            }

            $authorOffset = $this->utf16Length($chatText);
            $authorLength = $this->utf16Length($authorName);

            $chatText .= $authorName;

            $entities[] = [
                'type' => 'text_link',
                'offset' => $authorOffset,
                'length' => $authorLength,
                'url' => 'tg://user?id=' . $telegramUserId,
            ];

            $entities[] = [
                'type' => 'bold',
                'offset' => $authorOffset,
                'length' => $authorLength,
            ];

            /*
            |--------------------------------------------------------------------------
            | Текст сообщения без иконки
            |--------------------------------------------------------------------------
            */

            $chatText .= ': ';

            $textStartOffset = $this->utf16Length($chatText);

            $chatText .= $text;

            /*
            |--------------------------------------------------------------------------
            | Упоминания @username
            |--------------------------------------------------------------------------
            */

            if ($text !== '') {
                $mentionedUsers = $this->findMentionedUsers($text);

                foreach ($mentionedUsers as $mention) {
                    $mentionedUser = $mention['user'];

                    $beforeMention = mb_substr(
                        $text,
                        0,
                        $mention['offset'],
                        'UTF-8'
                    );

                    $mentionValue = mb_substr(
                        $text,
                        $mention['offset'],
                        $mention['length'],
                        'UTF-8'
                    );

                    $entities[] = [
                        'type' => 'text_link',
                        'offset' => $textStartOffset
                            + $this->utf16Length($beforeMention),
                        'length' => $this->utf16Length($mentionValue),
                        'url' => 'tg://user?id='
                            . (int) $mentionedUser->telegram_id,
                    ];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Отправляем задачу в очередь telegram
            |--------------------------------------------------------------------------
            */

            GlobalChatDeliveryJob::dispatch(
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

            Log::info(
                'GlobalChatDeliveryJob DISPATCHED FROM HANDLER',
                [
                    'chatMessageId' => $chatMessage->id,
                    'authorTelegramId' => $telegramUserId,
                    'messageType' => $messageType,
                    'hasMediaFileId' => $mediaFileId !== null,
                ]
            );

            return true;
        } catch (Throwable $e) {
            Log::error(
                'GlobalChatHandler error',
                [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return false;
        }
    }

    /**
     * Определяет тип сообщения.
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

        return null;
    }

    /**
     * Получает file_id медиа.
     */
    private function getMediaFileId(
        $message,
        ?string $messageType
    ): ?string {
        if ($messageType === null) {
            return null;
        }

        switch ($messageType) {
            case 'sticker':
                $media = $message->getSticker();
                break;

            case 'animation':
                $media = $message->getAnimation();
                break;

            case 'voice':
                $media = $message->getVoice();
                break;

            case 'video':
                $media = $message->getVideo();
                break;

            case 'photo':
                $photos = $message->getPhoto();

                if (!$photos || count($photos) === 0) {
                    return null;
                }

                $largestPhoto = null;
                $largestSize = -1;

                foreach ($photos as $photo) {
                    $fileSize = (int) (
                        $photo->getFileSize() ?? 0
                    );

                    if ($fileSize >= $largestSize) {
                        $largestSize = $fileSize;
                        $largestPhoto = $photo;
                    }
                }

                return $largestPhoto
                    ? $largestPhoto->getFileId()
                    : null;

            case 'audio':
                $media = $message->getAudio();
                break;

            case 'document':
                $media = $message->getDocument();
                break;

            default:
                return null;
        }

        return $media ? $media->getFileId() : null;
    }

    /**
     * Ищет пользователей по @username.
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
            $byteOffset = $match[1];

            $before = substr($text, 0, $byteOffset);

            $characterOffset = mb_strlen(
                $before,
                'UTF-8'
            );

            $user = TelegramUser::query()
                ->whereRaw(
                    'LOWER(username) = ?',
                    [
                        mb_strtolower(
                            $username,
                            'UTF-8'
                        ),
                    ]
                )
                ->first();

            if (!$user) {
                continue;
            }

            $result[] = [
                'user' => $user,
                'offset' => $characterOffset,
                'length' => mb_strlen(
                    '@' . $username,
                    'UTF-8'
                ),
            ];
        }

        return $result;
    }

    /**
     * Telegram использует UTF-16 offsets для entities.
     */
    private function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) (
            strlen(
                mb_convert_encoding(
                    $text,
                    'UTF-16LE',
                    'UTF-8'
                )
            ) / 2
        );
    }
}
