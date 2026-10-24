
<?php

namespace App\Console\Commands;

use App\Telegram\MuteEventHandler;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Console\Command;

class TelegramMuteListener extends Command
{
    protected $signature = 'telegram:mute-listener';

    protected $description = 'Слушатель автоматического мута в Telegram';

    public function handle(): int
    {
        $settings = (new \danog\MadelineProto\Settings())
            ->setAppInfo(
                (new \danog\MadelineProto\Settings\AppInfo())
                    ->setApiId((int) config('services.telegram.api_id'))
                    ->setApiHash(config('services.telegram.api_hash'))
            );

        $this->info('Запускаю слушатель Telegram-мута...');

        SimpleEventHandler::startAndLoop(
            MuteEventHandler::class,
            storage_path('telegram/user.session'),
            $settings
        );

        return self::SUCCESS;
    }
}
