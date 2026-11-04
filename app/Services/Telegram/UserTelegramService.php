<?php

namespace App\Services\Telegram;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;

class UserTelegramService
{
    private API $telegram;

    public function __construct()
    {
        $settings = new Settings();

        $settings->getAppInfo()
            ->setApiId((int) config('services.telegram.api_id'))
            ->setApiHash(config('services.telegram.api_hash'));

        $this->telegram = new API(
            storage_path('telegram/user.session'),
            $settings
        );
    }

    public function getClient(): API
    {
        return $this->telegram;
    }

    /**
     * Отправить сообщение через MTProto.
     */
    public function sendMessage(
    int|string $chatId,
    string $message,
    ?array $replyMarkup = null
): int {
    $params = [
        'peer' => $chatId,
        'message' => $message,
        'parse_mode' => 'HTML',
    ];

    if ($replyMarkup !== null) {
        $params['reply_markup'] = $replyMarkup;
    }

    $response = $this->telegram->messages->sendMessage($params);

    // Ответ в формате Bot API
    if (isset($response['message_id'])) {
        return (int) $response['message_id'];
    }

    // Ответ в формате MTProto
    if (isset($response['updates']) && is_array($response['updates'])) {
        foreach ($response['updates'] as $update) {
            if (
                isset($update['_']) &&
                $update['_'] === 'UpdateNewMessage' &&
                isset($update['message']['id'])
            ) {
                return (int) $update['message']['id'];
            }

            if (
                isset($update['_']) &&
                $update['_'] === 'UpdateNewChannelMessage' &&
                isset($update['message']['id'])
            ) {
                return (int) $update['message']['id'];
            }
        }
    }

    // Не скрываем неожиданный формат ответа
    \Log::error('Не удалось получить message_id из ответа Telegram MTProto', [
        'chat_id' => $chatId,
        'response' => $response,
    ]);

    throw new \RuntimeException(
        'Telegram отправил сообщение, но не удалось получить message_id'
    );
}

    /**
     * Закрепить сообщение через MTProto.
     */
    public function pinMessage(
        int|string $chatId,
        int $messageId
    ): void {
        $this->telegram->messages->updatePinnedMessage(
            peer: $chatId,
            id: $messageId,
            silent: true,
        );
    }

    /**
     * Удалить сообщение через MTProto.
     *
     * Для supergroup используется channels.deleteMessages.
     * Для обычной группы используется messages.deleteMessages.
     */
    public function deleteMessage(
        int|string $chatId,
        int $messageId,
        string $chatType = 'supergroup'
    ): void {
        if ($chatType === 'supergroup') {
            $this->telegram->channels->deleteMessages(
                channel: $chatId,
                id: [$messageId],
            );

            return;
        }

        $this->telegram->messages->deleteMessages(
            id: [$messageId],
            revoke: true,
        );
    }
}