<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class UserTelegramService
{
    private function request(string $method, array $params): array
    {
        $token = config('services.telegram.bot_token');

        $response = Http::timeout(30)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $params);

        $data = $response->json();

        if (!$response->successful() || !($data['ok'] ?? false)) {
            throw new RuntimeException(
                $data['description'] ?? 'Telegram API error'
            );
        }

        return $data['result'] ?? [];
    }

    public function sendMessage(
        int|string $chatId,
        string $message,
        ?array $replyMarkup = null
    ): int {
        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        $result = $this->request('sendMessage', $params);

        return (int) $result['message_id'];
    }

    public function editMessage(
        int|string $chatId,
        int $messageId,
        string $message,
        ?array $replyMarkup = null
    ): void {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        $this->request('editMessageText', $params);
    }

    public function pinMessage(
        int|string $chatId,
        int $messageId
    ): void {
        $this->request('pinChatMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'disable_notification' => true,
        ]);
    }

    public function deleteMessage(
        int|string $chatId,
        int $messageId,
        string $chatType = 'supergroup'
    ): void {
        $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }
}