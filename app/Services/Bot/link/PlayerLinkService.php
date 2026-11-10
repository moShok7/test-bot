<?php

namespace App\Services\Bot\link;

use Illuminate\Support\Facades\Cache;

class PlayerLinkService
{
    private const CACHE_PREFIX = 'player_link:';
    private const TTL_MINUTES = 10;

    public function generateCode(int $telegramId): string
    {
        do {
            $code = (string) random_int(111111, 999999);
        } while (Cache::has(self::CACHE_PREFIX . $code));

        Cache::put(
            self::CACHE_PREFIX . $code,
            $telegramId,
            now()->addMinutes(self::TTL_MINUTES)
        );

        return $code;
    }

    public function resolveTelegramId(string $code): ?int
    {
        $telegramId = Cache::get(self::CACHE_PREFIX . $code);

        return $telegramId ? (int) $telegramId : null;
    }

    public function consumeCode(string $code): void
    {
        Cache::forget(self::CACHE_PREFIX . $code);
    }
}