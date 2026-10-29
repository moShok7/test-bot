<?php

namespace App\Jobs;

use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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

    public int $tries = 5;
    public int $timeout = 120;
    public bool $failOnTimeout = true;

    public function backoff(): array
    {
        return [5, 15, 30, 60];
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
        $this->onQueue('broadcast');
    }

    public function handle(Api $telegram): void
    {
        $startedAt = microtime(true);

        if ($this->telegramUserIds === []) {
            return;
        }

        $recipients = TelegramUser::query()
            ->whereIn('telegram_id', $this->telegramUserIds)
            ->where('chat_notifications', true)
            ->where('telegram_id', '!=', $this->authorTelegramId)
            ->get();

        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($recipients as $recipient) {
            $delivery = $this->findOrCreateDelivery($recipient);

            if (!$delivery) {
                $skipped++;
                continue;
            }

            // Successful deliveries are immutable: a retry must not resend them.
            if ((int) $delivery->telegram_message_id > 0 || $delivery->status === 'sent') {
                if ($delivery->status !== 'sent') {
                    $delivery->forceFill(['status' => 'sent', 'processing_at' => null])->save();
                }
                $skipped++;
                continue;
            }

            // Atomically claim a pending/failed row, or recover a processing row
            // abandoned for more than 5 minutes by a dead worker.
            $staleBefore = now()->subMinutes(5);
            $claimed = ChatMessageDelivery::query()
                ->whereKey($delivery->getKey())
                ->where(function ($query) use ($staleBefore) {
                    $query->whereIn('status', ['pending', 'failed'])
                        ->orWhere(function ($query) use ($staleBefore) {
                            $query->where('status', 'processing')
                                ->where('processing_at', '<', $staleBefore);
                        });
                })
                ->update([
                    'status' => 'processing',
                    'processing_at' => now(),
                ]);

            if ($claimed !== 1) {
                $skipped++;
                continue;
            }

            try {
                Log::info('GlobalChat sending recipient', [
                    'chatMessageId' => $this->chatMessageId,
                    'telegramUserId' => $recipient->telegram_id,
                    'attempt' => $this->attempts(),
                ]);

                $sent = $this->sendToRecipient($telegram, $recipient);
                $last = collect($sent)->filter()->last();
                $messageId = $last ? (int) $last->getMessageId() : 0;

                if ($messageId <= 0) {
                    throw new \RuntimeException('Telegram returned no message ID');
                }

                $delivery->refresh();
                $delivery->forceFill([
                    'telegram_message_id' => $messageId,
                    'status' => 'sent',
                    'processing_at' => null,
                ])->save();

                $success++;
                Log::info('GlobalChat recipient sent', [
                    'chatMessageId' => $this->chatMessageId,
                    'telegramUserId' => $recipient->telegram_id,
                    'telegramMessageId' => $messageId,
                ]);

                // Conservative pacing for Telegram broadcasts.
                usleep(50_000);
            } catch (Throwable $e) {
                $message = $e->getMessage();

                if (preg_match('/retry after\s+(\d+)/i', $message, $matches)) {
                    $delivery->forceFill([
                        'status' => 'pending',
                        'processing_at' => null,
                    ])->save();

                    $delay = min(3600, max(1, (int) $matches[1] + 1));
                    Log::warning('GlobalChat 429, releasing batch', [
                        'chatMessageId' => $this->chatMessageId,
                        'retryAfter' => $delay,
                        'telegramUserId' => $recipient->telegram_id,
                    ]);
                    $this->release($delay);
                    return;
                }

                $this->handleTelegramError($recipient, $e);
                $lowerMessage = mb_strtolower($message);
                $permanent = str_contains($lowerMessage, 'bot was blocked')
                    || str_contains($lowerMessage, 'user is deactivated')
                    || str_contains($lowerMessage, 'chat not found')
                    || str_contains($lowerMessage, 'forbidden');

                $delivery->forceFill([
                    'status' => $permanent ? 'failed' : 'pending',
                    'processing_at' => null,
                ])->save();

                $failed++;

                // Retry the batch for transient failures. Rows already marked sent
                // are skipped on the next attempt.
                if (!$permanent) {
                    throw $e;
                }
            }
        }

        Log::info('GlobalChatDeliveryBatchJob DONE', [
            'chatMessageId' => $this->chatMessageId,
            'users' => count($recipients),
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'messageType' => $this->messageType,
            'duration' => round(microtime(true) - $startedAt, 3),
            'attempt' => $this->attempts(),
        ]);
    }

    private function findOrCreateDelivery(TelegramUser $recipient): ?ChatMessageDelivery
    {
        try {
            return ChatMessageDelivery::query()->create([
                'chat_message_id' => $this->chatMessageId,
                'telegram_user_id' => $recipient->id,
                'telegram_message_id' => 0,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE 23000 is a duplicate-key error on MySQL. Re-throw other DB errors.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            return ChatMessageDelivery::query()
                ->where('chat_message_id', $this->chatMessageId)
                ->where('telegram_user_id', $recipient->id)
                ->first();
        }
    }

    private function sendToRecipient(Api $telegram, TelegramUser $recipient): array
    {
        if ($this->messageType === 'sticker') {
            if (!$this->mediaFileId) {
                throw new \RuntimeException('Sticker file_id is empty');
            }

            $messages = [];
            if ($this->chatText !== '') {
                $messages[] = $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $this->chatText,
                    'parse_mode' => 'HTML',
                ]);
            }
            $messages[] = $telegram->sendSticker([
                'chat_id' => $recipient->telegram_id,
                'sticker' => $this->mediaFileId,
            ]);
            return $messages;
        }

        if ($this->messageType === 'text') {
            return [$telegram->sendMessage([
                'chat_id' => $recipient->telegram_id,
                'text' => $this->chatText,
                'parse_mode' => 'HTML',
            ])];
        }

        if (!$this->mediaFileId) {
            throw new \RuntimeException('Media file_id is empty');
        }

        $params = ['chat_id' => $recipient->telegram_id];
        if ($this->chatText !== '') {
            $params['caption'] = $this->chatText;
            $params['parse_mode'] = 'HTML';
        }
        return [$this->sendMedia($telegram, $params)];
    }

    private function sendMedia(Api $telegram, array $params)
    {
        switch ($this->messageType) {
            case 'animation':
                $params['animation'] = $this->mediaFileId;
                return $telegram->sendAnimation($params);
            case 'voice':
                $params['voice'] = $this->mediaFileId;
                return $telegram->sendVoice($params);
            case 'video':
                $params['video'] = $this->mediaFileId;
                return $telegram->sendVideo($params);
            case 'photo':
                $params['photo'] = $this->mediaFileId;
                return $telegram->sendPhoto($params);
            case 'audio':
                $params['audio'] = $this->mediaFileId;
                return $telegram->sendAudio($params);
            case 'document':
                $params['document'] = $this->mediaFileId;
                return $telegram->sendDocument($params);
            default:
                throw new \RuntimeException('Unsupported media type: ' . $this->messageType);
        }
    }

    private function handleTelegramError(TelegramUser $recipient, Throwable $exception): void
    {
        $message = $exception->getMessage();
        Log::warning('Global chat send error', [
            'chat_message_id' => $this->chatMessageId,
            'telegram_user_id' => $recipient->id,
            'telegram_id' => $recipient->telegram_id,
            'message_type' => $this->messageType,
            'message' => $message,
        ]);

        $lowerMessage = mb_strtolower($message);
        $disable = str_contains($lowerMessage, 'bot was blocked')
            || str_contains($lowerMessage, 'user is deactivated')
            || str_contains($lowerMessage, 'chat not found')
            || str_contains($lowerMessage, 'forbidden');

        if (!$disable) {
            return;
        }

        try {
            $recipient->update(['chat_notifications' => false]);
        } catch (Throwable $updateException) {
            Log::error('Failed to disable global chat notifications', [
                'telegram_user_id' => $recipient->id,
                'error' => $updateException->getMessage(),
            ]);
        }
    }
}
