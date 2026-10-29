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

    public int $tries = 3;
    public int $timeout = 120;

    private const BATCH_SIZE = 10;

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
    ) {
        $this->onQueue('broadcast');
    }

    public function handle(): void
    {
        $startedAt = microtime(true);

        Log::info('GlobalChatDeliveryJob START', [
            'chatMessageId' => $this->chatMessageId,
            'authorTelegramId' => $this->authorTelegramId,
            'messageType' => $this->messageType,
        ]);

        try {
            $telegramUserIds = TelegramUser::query()
                ->where('chat_notifications', true)
                ->where('telegram_id', '!=', $this->authorTelegramId)
                ->orderBy('id')
                ->pluck('telegram_id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();

            if ($telegramUserIds === []) {
                Log::info('GlobalChatDeliveryJob EMPTY', [
                    'chatMessageId' => $this->chatMessageId,
                ]);
                return;
            }

            $batches = array_chunk($telegramUserIds, self::BATCH_SIZE);

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

                Log::info('GlobalChatDeliveryBatchJob DISPATCHED', [
                    'chatMessageId' => $this->chatMessageId,
                    'batch' => $index + 1,
                    'recipients' => count($userIds),
                ]);
            }

            Log::info('GlobalChatDeliveryJob DONE', [
                'chatMessageId' => $this->chatMessageId,
                'recipients' => count($telegramUserIds),
                'batches' => count($batches),
                'duration' => round(microtime(true) - $startedAt, 3),
            ]);
        } catch (Throwable $e) {
            Log::error('GlobalChatDeliveryJob ERROR', [
                'chatMessageId' => $this->chatMessageId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }
}
