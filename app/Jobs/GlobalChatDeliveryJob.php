<?php

namespace App\Jobs;

use App\Models\TelegramUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GlobalChatDeliveryJob implements ShouldQueue
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
     * Максимальное время выполнения задачи.
     */
    public int $timeout = 120;

    /**
     * Размер пакета получателей.
     */
    private const BATCH_SIZE = 25;

    /**
     * Задержки перед повторными попытками.
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    /**
     * Данные сообщения.
     */
    public function __construct(
        public int $chatMessageId,
        public ?int $replyToChatMessageId,
        public int $authorTelegramId,
        public string $chatText,
        public array $entities,
        public string $messageType,
        public ?string $mediaFileId,
    ) {
        $this->onQueue('broadcast');
    }

    /**
     * Формируем и отправляем задачи пакетной рассылки.
     */
    public function handle(): void
    {
        $startedAt = microtime(true);

        Log::info(
            'GlobalChatDeliveryJob START',
            [
                'chatMessageId' => $this->chatMessageId,
                'authorTelegramId' => $this->authorTelegramId,
                'messageType' => $this->messageType,
            ]
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Выбираем получателей
            |--------------------------------------------------------------------------
            |
            | telegram_id — Telegram ID пользователя.
            | id — внутренний ID пользователя в БД.
            |--------------------------------------------------------------------------
            */

            $telegramUserIds = TelegramUser::query()
                ->where('chat_notifications', true)
                ->where('telegram_id', '!=', $this->authorTelegramId)
                ->orderBy('id')
                ->pluck('telegram_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            if (empty($telegramUserIds)) {
                Log::info(
                    'GlobalChatDeliveryJob EMPTY',
                    [
                        'chatMessageId' => $this->chatMessageId,
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Разбиваем получателей на пакеты
            |--------------------------------------------------------------------------
            */

            $batches = array_chunk($telegramUserIds, self::BATCH_SIZE);

            $dispatched = 0;

            foreach ($batches as $index => $userIds) {
                GlobalChatDeliveryBatchJob::dispatch(
                    chatMessageId: $this->chatMessageId,
                    replyToChatMessageId: $this->replyToChatMessageId,
                    authorTelegramId: $this->authorTelegramId,
                    chatText: $this->chatText,
                    entities: $this->entities,
                    messageType: $this->messageType,
                    mediaFileId: $this->mediaFileId,
                    telegramUserIds: $userIds,
                )->onQueue('broadcast');

                $dispatched++;

                Log::info(
                    'GlobalChatDeliveryBatchJob DISPATCHED',
                    [
                        'chatMessageId' => $this->chatMessageId,
                        'batch' => $index + 1,
                        'recipients' => count($userIds),
                    ]
                );
            }

            Log::info(
                'GlobalChatDeliveryJob DONE',
                [
                    'chatMessageId' => $this->chatMessageId,
                    'recipients' => count($telegramUserIds),
                    'batches' => $dispatched,
                    'duration' => round(
                        microtime(true) - $startedAt,
                        3
                    ),
                ]
            );
        } catch (Throwable $e) {
            Log::error(
                'GlobalChatDeliveryJob ERROR',
                [
                    'chatMessageId' => $this->chatMessageId,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            throw $e;
        }
    }
}