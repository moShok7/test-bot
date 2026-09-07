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
            ]
        );

        /*
         * Получатели глобального чата.
         *
         * Автор тоже НЕ получает свою копию.
         * Это оставляем как было.
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
                function ($recipients) use ($telegram) {
                    $deliveries = [];

                    foreach ($recipients as $recipient) {
                        try {
                            /*
                             * -------------------------------------------------
                             * Никакого reply_parameters.
                             *
                             * Reply уже находится внутри chatText.
                             * -------------------------------------------------
                             */

                            $sendParams = [
                                'chat_id' =>
                                    $recipient->telegram_id,

                                'text' =>
                                    $this->chatText,

                                /*
                                 * text_mention entities автора
                                 * передаются непосредственно Telegram.
                                 */
                                'entities' =>
                                    $this->entities,
                            ];

                            Log::info(
                                'TELEGRAM SEND',
                                [
                                    'recipient' =>
                                        $recipient->telegram_id,

                                    'chatMessageId' =>
                                        $this->chatMessageId,

                                    'replyToChatMessageId' =>
                                        $this->replyToChatMessageId,

                                    'sendParams' =>
                                        $sendParams,
                                ]
                            );

                            /*
                             * -------------------------------------------------
                             * Отправляем сообщение.
                             * -------------------------------------------------
                             */

                            $sentMessage =
                                $telegram->sendMessage(
                                    $sendParams
                                );

                            $sentTelegramMessageId =
                                $sentMessage->getMessageId();

                            /*
                             * -------------------------------------------------
                             * Сохраняем соответствие:
                             *
                             * ChatMessage
                             *      ↓
                             * recipient
                             *      ↓
                             * Telegram message_id
                             *
                             * Это необходимо для нашего Custom Reply.
                             * -------------------------------------------------
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
                                ]
                            );
                        } catch (Throwable $e) {
                            /*
                             * Ошибка одного пользователя
                             * не останавливает рассылку.
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

                                    'message' =>
                                        $e->getMessage(),

                                    'trace' =>
                                        $e->getTraceAsString(),
                                ]
                            );
                        }
                    }

                    /*
                     * ---------------------------------------------------------
                     * Записываем Telegram message_id.
                     * ---------------------------------------------------------
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
            ]
        );
    }
}

