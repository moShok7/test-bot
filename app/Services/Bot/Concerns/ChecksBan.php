<?php

namespace App\Services\Bot\Concerns;

use App\Models\TelegramUser;

trait ChecksBan
{
    protected function isBanned(TelegramUser $user): bool
    {
        return $user->ban()->exists();
    }
}