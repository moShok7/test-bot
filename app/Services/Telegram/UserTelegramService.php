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
     * Отправить сообщение через пользовательский Telegram-аккаунт.
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

        $response = $this->telegram->messages->sendMessage(
            $params
        );

        return (int) $response['message_id'];
    }
}