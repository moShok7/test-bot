<?php

namespace App\Jobs;

use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class GlobalChatDeliveryBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Максимальное количество попыток.
     */
    public int $tries = 3;

    /**
     * 50 пользователей.
     *
     * Даже если Telegram немного тормозит,
     * один job не должен висеть несколько минут.
     */
    public int $timeout = 120;

    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function __construct(
        public int $chatMessageId,
        public ?int $replyToChatMessageId,
        public int $authorTelegramId,
        public string $chatText,
        public array $entities,
        public string $messageType,
        public ?string $mediaFileId,
        public array $telegramUserIds,
    ) {
    }

    public function handle(Api $telegram): void
    {
        Log::info(
            'GlobalChatDeliveryBatchJob START',
            [
                'chatMessageId' => $this->chatMessageId,
                'users' => count($this->telegramUserIds),
                'messageType' => $this->messageType,
            ]
        );

        if (empty($this->telegramUserIds)) {
            return;
        }

        $encodedEntities = $this->encodeEntities();

        /*
        |--------------------------------------------------------------------------
        | Загружаем только нужных пользователей
        |--------------------------------------------------------------------------
        */

        $recipients = TelegramUser::query()
            ->whereIn(
                'telegram_id',
                $this->telegramUserIds
            )
            ->where(
                'chat_notifications',
                true
            )
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $sentMessages = $this->sendToRecipient(
                    telegram: $telegram,
                    recipient: $recipient,
                    encodedEntities: $encodedEntities,
                );

                foreach ($sentMessages as $sentMessage) {
                    if (!$sentMessage) {
                        continue;
                    }

                    $telegramMessageId =
                        $sentMessage->getMessageId();

                    if (!$telegramMessageId) {
                        continue;
                    }

                    ChatMessageDelivery::updateOrCreate(
                        [
                            'chat_message_id' =>
                                $this->chatMessageId,

                            'telegram_user_id' =>
                                $recipient->id,
                        ],
                        [
                            'telegram_message_id' =>
                                $telegramMessageId,

                            'updated_at' =>
                                now(),
                        ]
                    );

                    Log::info(
                        'GLOBAL CHAT SEND SUCCESS',
                        [
                            'chatMessageId' =>
                                $this->chatMessageId,

                            'telegramUserId' =>
                                $recipient->id,

                            'telegramId' =>
                                $recipient->telegram_id,

                            'telegramMessageId' =>
                                $telegramMessageId,

                            'messageType' =>
                                $this->messageType,
                        ]
                    );
                }
            } catch (Throwable $e) {
                $this->handleTelegramError(
                    recipient: $recipient,
                    exception: $e,
                );
            }
        }

        Log::info(
            'GlobalChatDeliveryBatchJob DONE',
            [
                'chatMessageId' => $this->chatMessageId,
                'users' => count($recipients),
                'messageType' => $this->messageType,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Отправка пользователю
    |--------------------------------------------------------------------------
    */

    private function sendToRecipient(
        Api $telegram,
        TelegramUser $recipient,
        string $encodedEntities,
    ): array {
        /*
        |--------------------------------------------------------------------------
        | STICKER
        |--------------------------------------------------------------------------
        |
        | Telegram sticker не имеет caption.
        |
        | Поэтому:
        |
        | 1. текст
        | 2. sticker
        |
        */

        if ($this->messageType === 'sticker') {
            if (!$this->mediaFileId) {
                throw new \RuntimeException(
                    'Sticker file_id is empty'
                );
            }

            $messages = [];

            if ($this->chatText !== '') {
                $messages[] = $telegram->sendMessage(
                    [
                        'chat_id' =>
                            $recipient->telegram_id,

                        'text' =>
                            $this->chatText,

                        'entities' =>
                            $encodedEntities,
                    ]
                );
            }

            $messages[] = $telegram->sendSticker(
                [
                    'chat_id' =>
                        $recipient->telegram_id,

                    'sticker' =>
                        $this->mediaFileId,
                ]
            );

            return $messages;
        }

        /*
        |--------------------------------------------------------------------------
        | TEXT
        |--------------------------------------------------------------------------
        */

        if ($this->messageType === 'text') {
            return [
                $telegram->sendMessage(
                    [
                        'chat_id' =>
                            $recipient->telegram_id,

                        'text' =>
                            $this->chatText,

                        'entities' =>
                            $encodedEntities,
                    ]
                ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | MEDIA
        |--------------------------------------------------------------------------
        */

        if (!$this->mediaFileId) {
            throw new \RuntimeException(
                'Media file_id is empty'
            );
        }

        $sendParams = [
            'chat_id' =>
                $recipient->telegram_id,
        ];

        if ($this->chatText !== '') {
            $sendParams['caption'] =
                $this->chatText;

            $sendParams['caption_entities'] =
                $encodedEntities;
        }

        return [
            $this->sendMedia(
                telegram: $telegram,
                sendParams: $sendParams,
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    private function sendMedia(
        Api $telegram,
        array $sendParams,
    ) {
        switch ($this->messageType) {
            case 'animation':
                $sendParams['animation'] =
                    $this->mediaFileId;

                return $telegram->sendAnimation(
                    $sendParams
                );

            case 'voice':
                $sendParams['voice'] =
                    $this->mediaFileId;

                return $telegram->sendVoice(
                    $sendParams
                );

            case 'video':
                $sendParams['video'] =
                    $this->mediaFileId;

                return $telegram->sendVideo(
                    $sendParams
                );

            case 'photo':
                $sendParams['photo'] =
                    $this->mediaFileId;

                return $telegram->sendPhoto(
                    $sendParams
                );

            case 'audio':
                $sendParams['audio'] =
                    $this->mediaFileId;

                return $telegram->sendAudio(
                    $sendParams
                );

            case 'document':
                $sendParams['document'] =
                    $this->mediaFileId;

                return $telegram->sendDocument(
                    $sendParams
                );

            default:
                throw new \RuntimeException(
                    'Unsupported media type: '
                    . $this->messageType
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Entities
    |--------------------------------------------------------------------------
    */

    private function encodeEntities(): string
    {
        return json_encode(
            $this->entities,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Ошибка Telegram
    |--------------------------------------------------------------------------
    */

    private function handleTelegramError(
        TelegramUser $recipient,
        Throwable $exception,
    ): void {
        $message = $exception->getMessage();

        Log::warning(
            'Global chat send error',
            [
                'chat_message_id' =>
                    $this->chatMessageId,

                'telegram_user_id' =>
                    $recipient->id,

                'telegram_id' =>
                    $recipient->telegram_id,

                'message_type' =>
                    $this->messageType,

                'message' =>
                    $message,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Пользователь заблокировал бота
        |--------------------------------------------------------------------------
        |
        | Telegram обычно возвращает:
        |
        | 403
        | bot was blocked by the user
        |
        | или:
        |
        | user is deactivated
        |
        */

        $lowerMessage = mb_strtolower(
            $message
        );

        $shouldDisableNotifications =
            str_contains(
                $lowerMessage,
                'bot was blocked'
            )
            ||
            str_contains(
                $lowerMessage,
                'user is deactivated'
            )
            ||
            str_contains(
                $lowerMessage,
                'chat not found'
            )
            ||
            str_contains(
                $lowerMessage,
                'forbidden'
            );

        if ($shouldDisableNotifications) {
            try {
                $recipient->update(
                    [
                        'chat_notifications' =>
                            false,
                    ]
                );

                Log::info(
                    'Global chat notifications DISABLED',
                    [
                        'telegram_user_id' =>
                            $recipient->id,

                        'telegram_id' =>
                            $recipient->telegram_id,

                        'reason' =>
                            $message,
                    ]
                );
            } catch (Throwable $updateException) {
                Log::error(
                    'Failed to disable global chat notifications',
                    [
                        'telegram_user_id' =>
                            $recipient->id,

                        'telegram_id' =>
                            $recipient->telegram_id,

                        'error' =>
                            $updateException->getMessage(),
                    ]
                );
            }
        }
    }
}