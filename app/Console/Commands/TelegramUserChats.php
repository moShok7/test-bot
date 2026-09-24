<?php

namespace App\Console\Commands;

use App\Services\Telegram\UserTelegramService;
use Illuminate\Console\Command;

class TelegramUserChats extends Command
{
    protected $signature = 'telegram:user-chats';

    protected $description = 'Показать группы Telegram-аккаунта';

    public function handle(UserTelegramService $telegramService): int
    {
        $telegram = $telegramService->getClient();

        $this->info('Получаю группы Telegram-аккаунта...');

        $dialogIds = $telegram->getDialogIds();

        foreach ($dialogIds as $peer) {
            try {
                $chat = $telegram->getPwrChat($peer);

                $type = $chat['type'] ?? null;

                // Только группы и супергруппы
                if ($type !== 'group' && $type !== 'supergroup') {
                    continue;
                }

                $id = $chat['id'] ?? 'unknown';
                $title = $chat['title'] ?? 'Без названия';

                $this->line(
                    $type . ' | ID: ' . $id . ' | ' . $title
                );
            } catch (\Throwable $e) {
                $this->warn(
                    'Не удалось получить чат: ' . $e->getMessage()
                );
            }
        }

        return self::SUCCESS;
    }
}