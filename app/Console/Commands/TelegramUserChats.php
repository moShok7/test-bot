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

        $dialogs = $telegram->getDialogs();

        foreach ($dialogs as $dialog) {
            $peer = $dialog['peer'] ?? null;

            if (!$peer) {
                continue;
            }

            // Обычная группа
            if (isset($peer['chat_id'])) {
                $this->line(
                    'GROUP | ID: ' . $peer['chat_id'] .
                    ' | ' . ($dialog['name'] ?? 'Без названия')
                );

                continue;
            }

            // Супергруппа / канал
            if (isset($peer['channel_id'])) {
                $this->line(
                    'CHANNEL/SUPERGROUP | ID: ' . $peer['channel_id'] .
                    ' | ' . ($dialog['name'] ?? 'Без названия')
                );
            }
        }

        return self::SUCCESS;
    }
}