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
    public function handle($message, Api $telegram): bool
    {
        try {
            $chat = $message->getChat();

            if (!$chat) {
                return false;
            }

            $chatId = (int) $chat->getId();
            $chatType = $chat->getType();

            if (in_array($chatType, ['group', 'supergroup'], true)) {
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

            $messageType = $this->detectMessageType($message);

            if ($messageType === null) {
                return false;
            }

            $from = $message->getFrom();

            if (!$from) {
                return false;
            }

            $telegramUserId = (int) $from->getId();

            $firstName = trim((string) ($from->getFirstName() ?? ''));
            $lastName = trim((string) ($from->getLastName() ?? ''));
            $username = $from->getUsername();

            $telegramUser = TelegramUser::updateOrCreate(
                ['telegram_id' => $telegramUserId],
                [
                    'username' => $username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]
            );

            $text = $message->getText();

            if ($text === null) {
                $text = $message->getCaption();
            }

            $text = (string) ($text ?? '');

            $mediaFileId = $this->getMediaFileId(
                $message,
                $messageType
            );

            /*
             * Определяем исходное сообщение, если это reply.
             */
            $replyToChatMessage = null;
            $replyMessage = $message->getReplyToMessage();

            if ($replyMessage) {
                $replyTelegramMessageId = (int) $replyMessage->getMessageId();

                $delivery = ChatMessageDelivery::query()
                    ->where('telegram_message_id', $replyTelegramMessageId)
                    ->where('telegram_user_id', $telegramUser->id)
                    ->first();

                if ($delivery) {
                    $replyToChatMessage = ChatMessage::find(
                        $delivery->chat_message_id
                    );
                }
            }

            /*
             * Сохраняем сообщение в текущую схему БД.
             */
            $storedMessage = $text !== ''
                ? $text
                : '[' . $messageType . ']';

            $chatMessage = ChatMessage::create([
                'telegram_user_id' => $telegramUser->id,
                'message' => $storedMessage,
            ]);

            /*
             * Формируем сообщение в одну строку:
             * @username: сообщение
             */
            if (!empty($username)) {
                $authorName = '@' . ltrim($username, '@');
                $profileUrl = 'https://t.me/' . ltrim($username, '@');
            } else {
                $authorName = $firstName !== ''
                    ? $firstName
                    : 'Пользователь';

                $profileUrl = 'tg://user?id=' . $telegramUserId;
            }

            $separator = ': ';
            $chatText = $authorName . $separator . $text;

            $authorLength = $this->utf16Length($authorName);
            $textStartOffset = $this->utf16Length($authorName . $separator);

            $entities = [
                [
                    'type' => 'bold',
                    'offset' => 0,
                    'length' => $authorLength,
                ],
                [
                    'type' => 'text_link',
                    'offset' => 0,
                    'length' => $authorLength,
                    'url' => $profileUrl,
                ],
            ];

            /*
             * Добавляем кликабельные упоминания.
             */
            if ($text !== '') {
                $mentionedUsers = $this->findMentionedUsers($text);

                foreach ($mentionedUsers as $mention) {
                    $mentionedUser = $mention['user'];

                    $mentionValue = mb_substr(
                        $text,
                        $mention['offset'],
                        $mention['length'],
                        'UTF-8'
                    );

                    $beforeMention = mb_substr(
                        $text,
                        0,
                        $mention['offset'],
                        'UTF-8'
                    );

                    $entities[] = [
                        'type' => 'text_link',
                        'offset' => $textStartOffset
                            + $this->utf16Length($beforeMention),
                        'length' => $this->utf16Length($mentionValue),
                        'url' => 'tg://openmessage?user_id='
                            . (int) $mentionedUser->telegram_id,
                    ];
                }
            }

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

            Log::info('GlobalChatDeliveryJob DISPATCHED FROM HANDLER', [
                'chatMessageId' => $chatMessage->id,
                'authorTelegramId' => $telegramUserId,
                'messageType' => $messageType,
                'hasMediaFileId' => $mediaFileId !== null,
            ]);

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
                    $fileSize = (int) ($photo->getFileSize() ?? 0);

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
            $characterOffset = mb_strlen($before, 'UTF-8') - 1;

            $user = TelegramUser::query()
                ->whereRaw(
                    'LOWER(username) = ?',
                    [mb_strtolower($username, 'UTF-8')]
                )
                ->first();

            if (!$user) {
                continue;
            }

            $result[] = [
                'user' => $user,
                'offset' => $characterOffset,
                'length' => mb_strlen('@' . $username, 'UTF-8'),
            ];
        }

        return $result;
    }

    private function utf16Length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) (
            strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2
        );
    }
}
