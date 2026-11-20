<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;

/**
 * Обрабатывает диплинк из лаунчера: https://t.me/{bot}?start=launcher_{token}.
 *
 * Подключается в TelegramBot.php ровно так же, как уже подключены
 * LOBBY DEEP LINK ("/start lobby_") и CLAN DEEP LINK ("/start clan_") —
 * см. инструкцию по вставке ниже в чате.
 *
 * TelegramBot.php сам отрезает префикс "/start launcher_" и передаёт
 * сюда уже чистый токен (32 hex-символа, генерируется в лаунчере через
 * TelegramLinkService.GenerateLinkToken()), поэтому здесь дополнительный
 * парсинг не нужен — только проверка формата на всякий случай.
 */
class LauncherLinkHandler
{
    private const TOKEN_TTL_DAYS = 90;

    public function handleStartPayload(int $telegramId, string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        Cache::put('launcher_token:' . $token, $telegramId, now()->addDays(self::TOKEN_TTL_DAYS));

        return "✅ Telegram привязан к лаунчеру.\nМожешь вернуться в лаунчер и запустить игру.";
    }
}