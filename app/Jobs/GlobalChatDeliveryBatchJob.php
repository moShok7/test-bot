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
        public string $messageType,
        public ?string $mediaFileId,
        public array $telegramUserIds,
    ) {
    }

    public function handle(Api $telegram): void
    {
        $startedAt = microtime(true);

        Log::info('GlobalChatDeliveryBatchJob START', [
            'chatMessageId' => $this->chatMessageId,
            'usersInBatch' => count($this->telegramUserIds),
            'messageType' => $this->messageType,
            'attempt' => $this->attempts(),
        ]);

        if (empty($this->telegramUserIds)) {
            Log::info('GlobalChatDeliveryBatchJob EMPTY', [
                'chatMessageId' => $this->chatMessageId,
            ]);

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
            $recipientStartedAt = microtime(true);

            try {
                // Не отправляем сообщение повторно, если доставка
                // уже зарегистрирована в базе.
                $alreadyDelivered = ChatMessageDelivery::query()
                    ->where('chat_message_id', $this->chatMessageId)
                    ->where('telegram_user_id', $recipient->id)
                    ->exists();

                if ($alreadyDelivered) {
                    $skipped++;

                    Log::info('GLOBAL CHAT SKIP ALREADY DELIVERED', [
                        'chatMessageId' => $this->chatMessageId,
                        'telegramUserId' => $recipient->id,
                        'telegramId' => $recipient->telegram_id,
                    ]);

                    continue;
                }

                $sentMessages = $this->sendToRecipient(
                    telegram: $telegram,
                    recipient: $recipient,
                );

                $savedDelivery = false;

                foreach ($sentMessages as $sentMessage) {
                    if (!$sentMessage) {
                        continue;
                    }

                    $telegramMessageId = $sentMessage->getMessageId();

                    if (!$telegramMessageId) {
                        continue;
                    }

                    ChatMessageDelivery::updateOrCreate(
                        [
                            'chat_message_id' => $this->chatMessageId,
                            'telegram_user_id' => $recipient->id,
                        ],
                        [
                            'telegram_message_id' => $telegramMessageId,
                            'updated_at' => now(),
                        ]
                    );

                    $savedDelivery = true;

                    Log::info('GLOBAL CHAT SEND SUCCESS', [
                        'chatMessageId' => $this->chatMessageId,
                        'telegramUserId' => $recipient->id,
                        'telegramId' => $recipient->telegram_id,
                        'telegramMessageId' => $telegramMessageId,
                        'messageType' => $this->messageType,
                    ]);
                }

                if ($savedDelivery) {
                    $success++;
                } else {
                    $failed++;

                    Log::warning('GLOBAL CHAT DELIVERY NOT RECORDED', [
                        'chatMessageId' => $this->chatMessageId,
                        'telegramUserId' => $recipient->id,
                        'telegramId' => $recipient->telegram_id,
                    ]);
                }
            } catch (Throwable $e) {
                $failed++;

                $this->handleTelegramError(
                    recipient: $recipient,
                    exception: $e,
                );
            } finally {
                Log::info('GLOBAL CHAT SEND TIMING', [
                    'chatMessageId' => $this->chatMessageId,
                    'telegramUserId' => $recipient->id,
                    'telegramId' => $recipient->telegram_id,
                    'totalDuration' => round(
                        microtime(true) - $recipientStartedAt,
                        3
                    ),
                ]);
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
                throw new \RuntimeException(
                    'Sticker file_id is empty'
                );
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
            throw new \RuntimeException(
                'Media file_id is empty'
            );
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
    private function sendMedia(
        Api $telegram,
        array $sendParams,
    ) {
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
            $recipient->update([
                'chat_notifications' => false,
            ]);

            Log::info('Global chat notifications DISABLED', [
                'telegram_user_id' => $recipient->id,
                'telegram_id' => $recipient->telegram_id,
                'reason' => $message,
            ]);
        } catch (Throwable $updateException) {
            Log::error('Failed to disable global chat notifications', [
                'telegram_user_id' => $recipient->id,
                'telegram_id' => $recipient->telegram_id,
                'error' => $updateException->getMessage(),
            ]);
        }
    }
}