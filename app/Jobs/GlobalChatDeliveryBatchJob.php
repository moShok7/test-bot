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
        $startedAt = microtime(true);

        if (empty($this->telegramUserIds)) {
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
            // Занимаем слот доставки. Уникальный индекс гарантирует,
            // что вставка пройдёт только у одного воркера.
            try {
                $delivery = ChatMessageDelivery::create([
                    'chat_message_id' => $this->chatMessageId,
                    'telegram_user_id' => $recipient->id,
                    'telegram_message_id' => 0,
                ]);
            } catch (QueryException $e) {
                if ((string) $e->getCode() === '23000') {
                    $skipped++;
                    continue;
                }

                throw $e;
            }

            try {
                $sent = $this->sendToRecipient($telegram, $recipient);

                $last = collect($sent)->filter()->last();

                $delivery->update([
                    'telegram_message_id' => $last
                        ? (int) $last->getMessageId()
                        : 0,
                ]);

                $success++;

                // Не упираемся в лимит Telegram (~30 сообщений/сек)
                usleep(50_000);
            } catch (Throwable $e) {
                // Освобождаем слот, чтобы повторная попытка могла
                // доставить это сообщение.
                $delivery->delete();

                // Лимит Telegram: откладываем весь батч и выходим.
                if (preg_match('/retry after (\d+)/i', $e->getMessage(), $m)) {
                    Log::warning('GlobalChat 429, releasing batch', [
                        'chatMessageId' => $this->chatMessageId,
                        'retryAfter' => (int) $m[1],
                    ]);

                    $this->release((int) $m[1] + 1);

                    return;
                }

                $failed++;

                $this->handleTelegramError(
                    recipient: $recipient,
                    exception: $e,
                );
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

    /**
     * Отправляет сообщение одному пользователю.
     */
    private function sendToRecipient(
        Api $telegram,
        TelegramUser $recipient,
    ): array {
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
            return [
                $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $this->chatText,
                    'parse_mode' => 'HTML',
                ]),
            ];
        }

        if (!$this->mediaFileId) {
            throw new \RuntimeException('Media file_id is empty');
        }

        $sendParams = [
            'chat_id' => $recipient->telegram_id,
        ];

        if ($this->chatText !== '') {
            $sendParams['caption'] = $this->chatText;
            $sendParams['parse_mode'] = 'HTML';
        }

        return [
            $this->sendMedia(
                telegram: $telegram,
                sendParams: $sendParams,
            ),
        ];
    }

    /**
     * Отправляет медиа соответствующего типа.
     */
    private function sendMedia(Api $telegram, array $sendParams)
    {
        switch ($this->messageType) {
            case 'animation':
                $sendParams['animation'] = $this->mediaFileId;
                return $telegram->sendAnimation($sendParams);

            case 'voice':
                $sendParams['voice'] = $this->mediaFileId;
                return $telegram->sendVoice($sendParams);

            case 'video':
                $sendParams['video'] = $this->mediaFileId;
                return $telegram->sendVideo($sendParams);

            case 'photo':
                $sendParams['photo'] = $this->mediaFileId;
                return $telegram->sendPhoto($sendParams);

            case 'audio':
                $sendParams['audio'] = $this->mediaFileId;
                return $telegram->sendAudio($sendParams);

            case 'document':
                $sendParams['document'] = $this->mediaFileId;
                return $telegram->sendDocument($sendParams);

            default:
                throw new \RuntimeException(
                    'Unsupported media type: ' . $this->messageType
                );
        }
    }

    /**
     * Обрабатывает ошибки отправки.
     */
    private function handleTelegramError(
        TelegramUser $recipient,
        Throwable $exception,
    ): void {
        $message = $exception->getMessage();

        Log::warning('Global chat send error', [
            'chat_message_id' => $this->chatMessageId,
            'telegram_user_id' => $recipient->id,
            'telegram_id' => $recipient->telegram_id,
            'message_type' => $this->messageType,
            'message' => $message,
        ]);

        $lowerMessage = mb_strtolower($message);

        $shouldDisableNotifications =
            str_contains($lowerMessage, 'bot was blocked')
            || str_contains($lowerMessage, 'user is deactivated')
            || str_contains($lowerMessage, 'chat not found')
            || str_contains($lowerMessage, 'forbidden');

        if (!$shouldDisableNotifications) {
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
