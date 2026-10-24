<?php

namespace App\Console\Commands;

use App\Telegram\MuteEventHandler;
use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use Illuminate\Console\Command;

class TelegramMuteListener extends Command
{
    protected $signature = 'telegram:mute-listener';

    protected $description = 'Слушатель автоматического мута в Telegram';

    public function handle(): int
    {
        $settings = new Settings();

        $settings->getAppInfo()
            ->setApiId((int) config('services.telegram.api_id'))
            ->setApiHash(config('services.telegram.api_hash'));

        $telegram = new API(
            storage_path('telegram/user.session'),
            $settings
        );

        $this->info('Запускаю слушатель Telegram-мута...');

        $telegram->startAndLoop(MuteEventHandler::class);

        return self::SUCCESS;
    }
}