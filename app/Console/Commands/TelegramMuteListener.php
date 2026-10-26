<?php

namespace App\Console\Commands;

use App\Services\Telegram\MuteEventHandler;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;
use Illuminate\Console\Command;

class TelegramMuteListener extends Command
{
    protected $signature = 'telegram:mute-listener';

    protected $description = 'Слушатель автоматического мута в Telegram';

    public function handle(): int
    {
        $settings = new Settings();

        $settings->setAppInfo(
            (new AppInfo())
                ->setApiId((int) config('services.telegram.api_id'))
                ->setApiHash(config('services.telegram.api_hash'))
        );

        $this->info('Запускаю слушатель Telegram-мута...');

        MuteEventHandler::startAndLoop(
            storage_path('telegram/user.session'),
            $settings
        );

        return self::SUCCESS;
    }
}