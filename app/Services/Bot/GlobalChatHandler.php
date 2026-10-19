<?php

namespace App\Services\Bot;

use App\Jobs\GlobalChatDeliveryJob;
use App\Models\BotSession;
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

            $telegramUser = TelegramUser::updateOrCreate(
                ['telegram_id' => $telegramUserId],
                [
                    'username' => $username,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | ЗАЩИТА: не отправлять ввод профиля в глобальный чат
            |--------------------------------------------------------------------------
            */

            if (!empty($telegramUser->profile_editing)) {
                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | ЗАЩИТА: не отправлять ввод игрового профиля в глобальный чат
            |--------------------------------------------------------------------------
            |
            | Проверяем только шаги игрового профиля, чтобы случайные
            | BotSession других функций не блокировали глобальный чат.
            |--------------------------------------------------------------------------
            */

            $gameProfileSteps = [
                'nickname',
                'game_id',
                'screenshot',
                'edit_nickname',
                'edit_game_id',
                'edit_screenshot',
            ];

            $isEditingGameProfile = BotSession::query()
                ->where('telegram_user_id', $telegramUser->id)
                ->whereIn('step', $gameProfileSteps)
                ->exists();

            if ($isEditingGameProfile) {
                return true;
            }

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
            | Медиа
            |--------------------------------------------------------------------------
            */

            $mediaFileId = $this->getMediaFileId(
                $message,
                $messageType
            );

            /*
            |--------------------------------------------------------------------------
            | Reply: поиск исходного сообщения
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
            | Сохранение сообщения в БД
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
            | Формирование HTML
            |--------------------------------------------------------------------------
            */

            $chatText = '';

            /*
            |--------------------------------------------------------------------------
            | Ответ на сообщение
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

                    $replyAuthorId = (int)
                        $replyAuthor->telegram_id;

                    $replyText = trim(
                        (string) $replyToChatMessage->message
                    );

                    if ($replyText === '') {
                        $replyText = 'Сообщение';
                    }

                    $chatText .= '↩️ <a href="tg://user?id='
                        . $replyAuthorId
                        . '"><b>'
                        . $this->escapeHtml($replyAuthorName)
                        . '</b></a>'
                        . "\n"
                        . $this->escapeHtml($replyText)
                        . "\n\n";
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Имя автора: HTML-ссылка на профиль
            |--------------------------------------------------------------------------
            */

            $authorName = trim($firstName . ' ' . $lastName);

            if ($authorName === '') {
                $authorName = 'Пользователь';
            }

            $chatText .= '<a href="tg://user?id='
                . $telegramUserId
                . '"><b>'
                . $this->escapeHtml($authorName)
                . '</b></a>: ';

            /*
            |--------------------------------------------------------------------------
            | Текст с кликабельными @username
            |--------------------------------------------------------------------------
            */

            $chatText .= $this->formatTextWithMentions($text);

            /*
            |--------------------------------------------------------------------------
            | Отправка задачи в очередь
            |--------------------------------------------------------------------------
            |
            | entities оставляем пустым массивом для совместимости
            | с существующим GlobalChatDeliveryJob.
            |--------------------------------------------------------------------------
            */

            GlobalChatDeliveryJob::dispatch(
                chatMessageId: $chatMessage->id,
                replyToChatMessageId: $replyToChatMessage
                    ? $replyToChatMessage->id
                    : null,
                authorTelegramId: $telegramUserId,
                chatText: $chatText,
                entities: [],
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
     * Экранирует текст для Telegram HTML.
     */
    private function escapeHtml(string $text): string
    {
        return htmlspecialchars(
            $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    /**
     * Преобразует известные @username в HTML-ссылки.
     * Остальной текст безопасно экранируется.
     */
    private function formatTextWithMentions(string $text): string
    {
        if ($text === '') {
            return '';
        }

        preg_match_all(
            '/@([a-zA-Z0-9_]{5,32})/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        if (empty($matches[0])) {
            return $this->escapeHtml($text);
        }

        $result = '';
        $cursor = 0;

        foreach ($matches[0] as $index => $match) {
            $fullMention = $match[0];
            $byteOffset = $match[1];

            $username = $matches[1][$index][0];

            $result .= $this->escapeHtml(
                substr(
                    $text,
                    $cursor,
                    $byteOffset - $cursor
                )
            );

            $user = TelegramUser::query()
                ->whereRaw(
                    'LOWER(username) = ?',
                    [mb_strtolower($username, 'UTF-8')]
                )
                ->first();

            if ($user) {
                $result .= '<a href="tg://user?id='
                    . (int) $user->telegram_id
                    . '">'
                    . $this->escapeHtml($fullMention)
                    . '</a>';
            } else {
                $result .= $this->escapeHtml($fullMention);
            }

            $cursor = $byteOffset + strlen($fullMention);
        }

        $result .= $this->escapeHtml(
            substr($text, $cursor)
        );

        return $result;
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
}
