<?php

namespace App\Console\Commands;

use App\Services\Telegram\UserTelegramService;
use Illuminate\Console\Command;

class TelegramUserInfo extends Command
{
    protected $signature = 'telegram:user-info';

    protected $description = 'Показать информацию об авторизованном Telegram-аккаунте';

    public function handle(UserTelegramService $telegramService): int
    {
        $telegram = $telegramService->getClient();

        $me = $telegram->getSelf();

        $this->info('Telegram аккаунт авторизован:');
        $this->line('ID: ' . ($me['id'] ?? 'unknown'));
        $this->line('Имя: ' . ($me['first_name'] ?? ''));
        $this->line('Фамилия: ' . ($me['last_name'] ?? ''));
        $this->line('Username: @' . ($me['username'] ?? 'нет'));

        return self::SUCCESS;
    }
}