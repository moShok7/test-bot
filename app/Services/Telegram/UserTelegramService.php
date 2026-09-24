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

        $this->telegram = new API(
            storage_path('telegram/user.session'),
            $settings
        );
    }

    public function getClient(): API
    {
        return $this->telegram;
    }
}