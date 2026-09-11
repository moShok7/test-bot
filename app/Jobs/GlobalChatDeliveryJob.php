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

class GlobalChatDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

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
        public string $messageType = 'text',
        public ?string $mediaFileId = null,
    ) {
    }

    public function handle(Api $telegram): void
    {
        Log::info(
            'GlobalChatDeliveryJob START',
            [
                'chatMessageId' =>
                    $this->chatMessageId,

                'replyToChatMessageId' =>
                    $this->replyToChatMessageId,

                'authorTelegramId' =>
                    $this->authorTelegramId,

                'messageType' =>
                    $this->messageType,

                'mediaFileId' =>
                    $this->mediaFileId,

                'entities' =>
                    $this->entities,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Telegram entities
        |--------------------------------------------------------------------------
        |
        | Telegram Bot API ожидает entities как JSON-serialized array.
        |
        */

        $encodedEntities = $this->encodeEntities();

        TelegramUser::query()
            ->where(
                'telegram_id',
                '!=',
                $this->authorTelegramId
            )
            ->where(
                'chat_notifications',
                true
            )
            ->orderBy('id')
            ->chunkById(
                100,
                function ($recipients) use (
                    $telegram,
                    $encodedEntities
                ) {
                    $deliveries = [];

                    foreach ($recipients as $recipient) {
                        try {
                            /*
                            |--------------------------------------------------------------------------
                            | STICKER
                            |--------------------------------------------------------------------------
                            */

                            if ($this->messageType === 'sticker') {
                                /*
                                | Sticker сам по себе не поддерживает
                                | caption/entities.
                                |
                                | Поэтому сначала отправляем текст,
                                | затем sticker.
                                */

                                if ($this->chatText !== '') {
                                    $headerParams = [
                                        'chat_id' =>
                                            $recipient->telegram_id,

                                        'text' =>
                                            $this->chatText,

                                        'entities' =>
                                            $encodedEntities,
                                    ];

                                    Log::info(
                                        'TELEGRAM SEND STICKER HEADER',
                                        [
                                            'recipient' =>
                                                $recipient->telegram_id,

                                            'entities' =>
                                                $this->entities,
                                        ]
                                    );

                                    $telegram->sendMessage(
                                        $headerParams
                                    );
                                }

                                if (!$this->mediaFileId) {
                                    throw new \RuntimeException(
                                        'Sticker file_id is empty'
                                    );
                                }

                                $sendParams = [
                                    'chat_id' =>
                                        $recipient->telegram_id,

                                    'sticker' =>
                                        $this->mediaFileId,
                                ];

                                Log::info(
                                    'TELEGRAM SEND STICKER',
                                    [
                                        'recipient' =>
                                            $recipient->telegram_id,

                                        'chatMessageId' =>
                                            $this->chatMessageId,

                                        'sendParams' =>
                                            $sendParams,
                                    ]
                                );

                                $sentMessage =
                                    $telegram->sendSticker(
                                        $sendParams
                                    );
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | TEXT
                            |--------------------------------------------------------------------------
                            */

                            elseif (
                                $this->messageType === 'text'
                            ) {
                                $sendParams = [
                                    'chat_id' =>
                                        $recipient->telegram_id,

                                    'text' =>
                                        $this->chatText,

                                    'entities' =>
                                        $encodedEntities,
                                ];

                                Log::info(
                                    'TELEGRAM SEND TEXT',
                                    [
                                        'recipient' =>
                                            $recipient->telegram_id,

                                        'chatMessageId' =>
                                            $this->chatMessageId,

                                        'entities' =>
                                            $this->entities,

                                        'encodedEntities' =>
                                            $encodedEntities,
                                    ]
                                );

                                $sentMessage =
                                    $telegram->sendMessage(
                                        $sendParams
                                    );
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | MEDIA
                            |--------------------------------------------------------------------------
                            */

                            else {
                                if (!$this->mediaFileId) {
                                    throw new \RuntimeException(
                                        'Media file_id is empty'
                                    );
                                }

                                $sendParams = [
                                    'chat_id' =>
                                        $recipient->telegram_id,
                                ];

                                /*
                                |--------------------------------------------------------------
                                | Caption
                                |--------------------------------------------------------------
                                */

                                if ($this->chatText !== '') {
                                    $sendParams['caption'] =
                                        $this->chatText;

                                    $sendParams['caption_entities'] =
                                        $encodedEntities;
                                }

                                $sentMessage =
                                    $this->sendMedia(
                                        $telegram,
                                        $sendParams
                                    );
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Telegram message_id
                            |--------------------------------------------------------------------------
                            */

                            $sentTelegramMessageId =
                                $sentMessage->getMessageId();

                            /*
                            |--------------------------------------------------------------------------
                            | Сохраняем связь
                            |--------------------------------------------------------------------------
                            */

                            $now = now();

                            $deliveries[] = [
                                'chat_message_id' =>
                                    $this->chatMessageId,

                                'telegram_user_id' =>
                                    $recipient->id,

                                'telegram_message_id' =>
                                    $sentTelegramMessageId,

                                'created_at' =>
                                    $now,

                                'updated_at' =>
                                    $now,
                            ];

                            Log::info(
                                'TELEGRAM SEND SUCCESS',
                                [
                                    'recipient' =>
                                        $recipient->telegram_id,

                                    'telegramMessageId' =>
                                        $sentTelegramMessageId,

                                    'chatMessageId' =>
                                        $this->chatMessageId,

                                    'messageType' =>
                                        $this->messageType,
                                ]
                            );
                        } catch (Throwable $e) {
                            /*
                            |--------------------------------------------------------------------------
                            | Ошибка одного пользователя
                            |--------------------------------------------------------------------------
                            */

                            Log::warning(
                                'Global chat send error',
                                [
                                    'telegram_user_id' =>
                                        $recipient->id,

                                    'telegram_id' =>
                                        $recipient->telegram_id,

                                    'chat_message_id' =>
                                        $this->chatMessageId,

                                    'messageType' =>
                                        $this->messageType,

                                    'message' =>
                                        $e->getMessage(),

                                    'trace' =>
                                        $e->getTraceAsString(),
                                ]
                            );
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Сохраняем Telegram message_id
                    |--------------------------------------------------------------------------
                    */

                    if (!empty($deliveries)) {
                        ChatMessageDelivery::upsert(
                            $deliveries,
                            [
                                'chat_message_id',
                                'telegram_user_id',
                            ],
                            [
                                'telegram_message_id',
                                'updated_at',
                            ]
                        );
                    }
                }
            );

        Log::info(
            'GlobalChatDeliveryJob DONE',
            [
                'chatMessageId' =>
                    $this->chatMessageId,

                'replyToChatMessageId' =>
                    $this->replyToChatMessageId,

                'messageType' =>
                    $this->messageType,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | JSON для Telegram entities
    |--------------------------------------------------------------------------
    */

    private function encodeEntities(): string
    {
        return json_encode(
            $this->entities,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Отправка media
    |--------------------------------------------------------------------------
    */

    private function sendMedia(
        Api $telegram,
        array $sendParams
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
}