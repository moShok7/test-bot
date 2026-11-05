<?php

namespace App\Services\Bot\link;

use App\Services\Bot\link\PlayerLinkService;
use Telegram\Bot\Api;

class PlayerLinkHandler
{
    public function __construct(
        private readonly PlayerLinkService $playerLinkService = new PlayerLinkService()
    ) {
    }

    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));

        if ($text !== '/link') {
            return false;
        }

        $chatId = $message->chat->id ?? null;
        $telegramId = $message->from->id ?? null;

        if (!$chatId || !$telegramId) {
            return false;
        }

        $code = $this->playerLinkService->generateCode((int) $telegramId);

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🔗 Твой код привязки: *{$code}*\n\n" .
                "Введи его в игре в поле ввода кода комнаты (как при подключении к чужому лобби) — " .
                "игровой аккаунт привяжется автоматически.\n\n" .
                "Код действует 10 минут.",
            'parse_mode' => 'Markdown',
        ]);

        return true;
    }
}