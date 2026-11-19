<?php

namespace App\Services\Bot;

use Illuminate\Support\Facades\Cache;

/**
 * Обрабатывает payload диплинка из лаунчера: https://t.me/{bot}?start={token}.
 * Telegram при переходе по такой ссылке и нажатии Start присылает боту
 * сообщение "/start {token}" — именно этот payload здесь и разбирается.
 *
 * Подключить в ваш командный цикл (App\Console\Commands\TelegramBot),
 * там же, где уже разбираются остальные команды, например:
 *
 *   if (str_starts_with($text, '/start')) {
 *       $payload = trim(substr($text, strlen('/start')));
 *       if ($payload !== '') {
 *           $reply = (new LauncherLinkHandler())->handleStartPayload($telegramId, $payload);
 *           if ($reply !== null) {
 *               // отправить $reply этому пользователю как обычный ответ бота
 *               return;
 *           }
 *       }
 *       // ...обычная обработка /start без payload (как было раньше)
 *   }
 */
class LauncherLinkHandler
{
    private const TOKEN_TTL_DAYS = 90;

    /**
     * $telegramId — отправитель сообщения /start (из Telegram update).
     * $payload — то, что передано после "/start " (токен из диплинка лаунчера).
     *
     * Возвращает текст ответа пользователю, если это действительно был токен
     * лаунчера, или null — если payload не похож на токен (значит, это был
     * обычный /start без параметра для нашего функционала, и его нужно
     * обработать как раньше).
     */
    public function handleStartPayload(int $telegramId, string $payload): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $payload)) {
            return null;
        }

        Cache::put('launcher_token:' . $payload, $telegramId, now()->addDays(self::TOKEN_TTL_DAYS));

        return "✅ Telegram привязан к лаунчеру.\nМожешь вернуться в лаунчер и запустить игру.";
    }
}
