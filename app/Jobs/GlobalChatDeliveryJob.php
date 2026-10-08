<?php

namespace App\Jobs;

use App\Models\TelegramUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GlobalChatDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Количество попыток.
     */
    public int $tries = 3;

    /**
     * Максимальное время выполнения.
     */
    public int $timeout = 120;

    /**
     * Задержки повторных попыток.
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
        public string $messageType = 'text',
        public ?string $mediaFileId = null,
    ) {
    }

    /**
     * Создаём отдельные BatchJob по 50 пользователей.
     */
    public function handle(): void
    {
        $startedAt = microtime(true);

        Log::info(
            'GlobalChatDeliveryJob START',
            [
                'chatMessageId' => $this->chatMessageId,
                'replyToChatMessageId' => $this->replyToChatMessageId,
                'authorTelegramId' => $this->authorTelegramId,
                'messageType' => $this->messageType,
                'mediaFileId' => $this->mediaFileId,
            ]
        );

        $batches = 0;
        $users = 0;

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
                50,
                function ($recipients) use (&$batches, &$users): void {
                    $telegramUserIds = $recipients
                        ->pluck('telegram_id')
                        ->map(
                            fn ($id) => (int) $id
                        )
                        ->values()
                        ->all();

                    if (empty($telegramUserIds)) {
                        return;
                    }

                    $count = count($telegramUserIds);

                    $users += $count;
                    $batches++;

                    GlobalChatDeliveryBatchJob::dispatch(
                        chatMessageId: $this->chatMessageId,
                        replyToChatMessageId: $this->replyToChatMessageId,
                        authorTelegramId: $this->authorTelegramId,
                        chatText: $this->chatText,
                        entities: $this->entities,
                        messageType: $this->messageType,
                        mediaFileId: $this->mediaFileId,
                        telegramUserIds: $telegramUserIds,
                    )->onQueue('telegram');

                    Log::info(
                        'GlobalChatDeliveryBatchJob DISPATCHED',
                        [
                            'chatMessageId' => $this->chatMessageId,
                            'batch' => $batches,
                            'users' => $count,
                            'messageType' => $this->messageType,
                        ]
                    );
                }
            );

        $duration = round(
            microtime(true) - $startedAt,
            3
        );

        Log::info(
            'GlobalChatDeliveryJob DONE',
            [
                'chatMessageId' => $this->chatMessageId,
                'batches' => $batches,
                'users' => $users,
                'messageType' => $this->messageType,
                'duration' => $duration,
            ]
        );
    }
}