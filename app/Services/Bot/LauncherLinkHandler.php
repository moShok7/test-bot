<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;
use Illuminate\Support\Facades\Cache;

class LauncherLinkHandler
{
    private const TOKEN_TTL_DAYS = 90;

    public function handleStartPayload(
        int $telegramId,
        string $payload
    ): ?string {
        if ($telegramId <= 0) {
            return null;
        }

        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $payload)) {
            return null;
        }

        $user = TelegramUser::query()
            ->where('telegram_id', $telegramId)
            ->first();

        if (!$user) {
            return "❌ Сначала зарегистрируйся в боте.";
        }

        $cacheKey = 'launcher_token:' . $payload;

        // Не разрешаем привязать уже использованный токен
        // к другому Telegram-аккаунту.
        $existingTelegramId = Cache::get($cacheKey);

        if (
            $existingTelegramId !== null &&
            (int) $existingTelegramId !== $telegramId
        ) {
            return "❌ Эта ссылка уже использована. Создай новую в лаунчере.";
        }

        Cache::put(
            $cacheKey,
            $telegramId,
            now()->addDays(self::TOKEN_TTL_DAYS)
        );

        return "✅ Telegram успешно привязан к лаунчеру!\n\n"
            . "👤 Аккаунт: @"
            . ($user->username ?: 'без username')
            . "\n\n"
            . "Можешь вернуться в лаунчер и продолжить.";
    }
}
