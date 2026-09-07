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

    /**
     * Количество попыток Job.
     */
    public int $tries = 3;

    /**
     * Таймаут Job.
     */
    public int $timeout = 120;

    /**
     * Повторная попытка.
     */
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
    ) {
    }

    /**
     * Выполняет рассылку.
     */
    public function handle(Api $telegram): void
    {
        Log::info('RUNNING GlobalChatDeliveryJob', [
            'chatMessageId' => $this->chatMessageId,
            'replyToChatMessageId' => $this->replyToChatMessageId,
            'authorTelegramId' => $this->authorTelegramId,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Получаем исходные сообщения для Reply
        |--------------------------------------------------------------------------
        */

        $originalDeliveries = collect();

        if ($this->replyToChatMessageId) {
            $originalDeliveries = ChatMessageDelivery::query()
                ->where(
                    'chat_message_id',
                    $this->replyToChatMessageId
                )
                ->get()
                ->keyBy('telegram_user_id');

            Log::info('REPLY ORIGINAL DELIVERIES', [
                'chatMessageId' => $this->replyToChatMessageId,
                'count' => $originalDeliveries->count(),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Получатели
        |--------------------------------------------------------------------------
        */

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
                    $originalDeliveries
                ) {
                    $deliveries = [];

                    foreach ($recipients as $recipient) {
                        try {
                            /*
                            |--------------------------------------------------------------------------
                            | Базовые параметры
                            |--------------------------------------------------------------------------
                            */

                            $sendParams = [
                                'chat_id' => $recipient->telegram_id,
                                'text' => $this->chatText,
                                'entities' => $this->entities,
                            ];

                            /*
                            |--------------------------------------------------------------------------
                            | Telegram Reply
                            |--------------------------------------------------------------------------
                            */

                            $originalDelivery = null;

                            if ($this->replyToChatMessageId) {
                                $originalDelivery = $originalDeliveries->get(
                                    $recipient->id
                                );

                                if ($originalDelivery) {
                                    $sendParams['reply_parameters'] = [
                                        'message_id' => (int) $originalDelivery->telegram_message_id,
                                    ];
                                }
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Диагностика
                            |--------------------------------------------------------------------------
                            */

                            Log::info('TELEGRAM SEND', [
                                'recipient' => $recipient->telegram_id,

                                'chatMessageId' => $this->chatMessageId,

                                'replyToChatMessageId' =>
                                    $this->replyToChatMessageId,

                                'originalDeliveryId' =>
                                    $originalDelivery?->id,

                                'originalTelegramMessageId' =>
                                    $originalDelivery?->telegram_message_id,

                                'hasReplyParameters' =>
                                    isset($sendParams['reply_parameters']),
                            ]);

                            /*
                            |--------------------------------------------------------------------------
                            | Отправляем сообщение
                            |--------------------------------------------------------------------------
                            */

                            $sentMessage = $telegram->sendMessage(
                                $sendParams
                            );

                            $sentTelegramMessageId =
                                $sentMessage->getMessageId();

                            /*
                            |--------------------------------------------------------------------------
                            | Сохраняем delivery
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

                                'created_at' => $now,

                                'updated_at' => $now,
                            ];

                            Log::info('TELEGRAM SENT', [
                                'recipient' => $recipient->telegram_id,
                                'telegramMessageId' => $sentTelegramMessageId,
                                'replyToTelegramMessageId' =>
                                    $originalDelivery?->telegram_message_id,
                            ]);

                        } catch (Throwable $e) {
                            Log::error(
                                'Global chat send error',
                                [
                                    'telegram_user_id' =>
                                        $recipient->id,

                                    'chat_message_id' =>
                                        $this->chatMessageId,

                                    'message' =>
                                        $e->getMessage(),

                                    'exception' =>
                                        get_class($e),
                                ]
                            );
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Массовое сохранение deliveries
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

        Log::info('DONE GlobalChatDeliveryJob', [
            'chatMessageId' => $this->chatMessageId,
        ]);
    }
}