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
            'users' => count($this->telegramUserIds),
            'messageType' => $this->messageType,
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
            ->get();

        $success = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                $sentMessages = $this->sendToRecipient(
                    telegram: $telegram,
                    recipient: $recipient,
                );

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

                    Log::info('GLOBAL CHAT SEND SUCCESS', [
                        'chatMessageId' => $this->chatMessageId,
                        'telegramUserId' => $recipient->id,
                        'telegramId' => $recipient->telegram_id,
                        'telegramMessageId' => $telegramMessageId,
                        'messageType' => $this->messageType,
                    ]);
                }

                $success++;
            } catch (Throwable $e) {
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
            'messageType' => $this->messageType,
            'duration' => round(microtime(true) - $startedAt, 3),
        ]);
    }

    private function sendToRecipient(
        Api $telegram,
        TelegramUser $recipient,
    ): array {
        /*
         * Стикер отправляется отдельным сообщением.
         * Текст перед ним сохраняет ник и сообщение в одной строке.
         */
        if ($this->messageType === 'sticker') {
            if (!$this->mediaFileId) {
                throw new \RuntimeException('Sticker file_id is empty');
            }

            $messages = [];

            if ($this->chatText !== '') {
                $messages[] = $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $this->chatText,
                    'entities' => $this->entities,
                ]);
            }

            $messages[] = $telegram->sendSticker([
                'chat_id' => $recipient->telegram_id,
                'sticker' => $this->mediaFileId,
            ]);

            return $messages;
        }

        /*
         * Обычный текст.
         */
        if ($this->messageType === 'text') {
            return [
                $telegram->sendMessage([
                    'chat_id' => $recipient->telegram_id,
                    'text' => $this->chatText,
                    'entities' => $this->entities,
                ]),
            ];
        }

        /*
         * Медиа.
         */
        if (!$this->mediaFileId) {
            throw new \RuntimeException('Media file_id is empty');
        }

        $sendParams = [
            'chat_id' => $recipient->telegram_id,
        ];

        if ($this->chatText !== '') {
            $sendParams['caption'] = $this->chatText;
            $sendParams['caption_entities'] = $this->entities;
        }

        return [
            $this->sendMedia(
                telegram: $telegram,
                sendParams: $sendParams,
            ),
        ];
    }

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
