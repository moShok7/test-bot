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
     * Повторная попытка через 5 секунд.
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
        /*
        |--------------------------------------------------------------------------
        | Если это Reply
        |--------------------------------------------------------------------------
        |
        | Получаем ВСЕ исходные delivery одним SELECT.
        |
        | Было:
        |
        | 60 пользователей = 60 SELECT
        |
        | Теперь:
        |
        | 1 SELECT
        |
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
                            | Параметры сообщения
                            |--------------------------------------------------------------------------
                            */

                            $sendParams = [
                                'chat_id' => $recipient->telegram_id,
                                'text' => $this->chatText,
                                'entities' => $this->entities,
                            ];

                            /*
                            |--------------------------------------------------------------------------
                            | НАСТОЯЩИЙ Telegram Reply
                            |--------------------------------------------------------------------------
                            |
                            | Telegram сам нарисует Reply-блок.
                            |
                            */

                            if ($this->replyToChatMessageId) {
                                $originalDelivery =
                                    $originalDeliveries->get(
                                        $recipient->id
                                    );

                                if ($originalDelivery) {
                                    $sendParams['reply_parameters'] = [
                                        'message_id' =>
                                            $originalDelivery
                                                ->telegram_message_id,
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

                            $sentTelegramMessageId =
                                $sentMessage->getMessageId();

                            /*
                            |--------------------------------------------------------------------------
                            | НЕ делаем updateOrCreate здесь
                            |--------------------------------------------------------------------------
                            |
                            | Вместо 60 SELECT + 60 INSERT/UPDATE
                            | собираем данные и одним upsert сохраняем
                            | всю пачку.
                            |
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

                        } catch (Throwable $e) {
                            Log::warning(
                                'Global chat send error',
                                [
                                    'telegram_user_id' =>
                                        $recipient->id,

                                    'chat_message_id' =>
                                        $this->chatMessageId,

                                    'message' =>
                                        $e->getMessage(),
                                ]
                            );
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Сохраняем deliveries одной операцией
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
    }
}