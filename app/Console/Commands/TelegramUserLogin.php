<?php

namespace App\Console\Commands;

use App\Services\Telegram\UserTelegramService;
use Illuminate\Console\Command;

class TelegramUserLogin extends Command
{
    protected $signature = 'telegram:user-login';

    protected $description = 'Авторизация Telegram user account через MTProto';

    public function handle(UserTelegramService $telegramService): int
    {
        $this->info('Запускаю авторизацию Telegram-аккаунта...');

        $telegram = $telegramService->getClient();

        $telegram->start();

        $this->info('Telegram-аккаунт успешно авторизован.');

        return self::SUCCESS;
    }
}