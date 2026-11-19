<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Доступ к лаунчеру разрешён только тем, кто зарегистрирован в Telegram-боте.
 *
 * БЕЗ ручного ввода кода: лаунчер сам генерирует случайный токен и открывает
 * https://t.me/{bot}?start={token}. Игрок просто жмёт Start — бот обрабатывает
 * payload из /start (см. LauncherLinkHandler) и кладёт привязку в Cache.
 * Лаунчер всё это время опрашивает /api/launcher/status/{token}.
 *
 * Новых миграций не требуется: токен лаунчера хранится в Cache
 * (CACHE_STORE=database), так что переживает рестарт контейнеров.
 *
 * Добавить в routes/api.php:
 *   use App\Http\Controllers\Api\LauncherAuthController;
 *   Route::get('/launcher/status/{token}', [LauncherAuthController::class, 'status']);
 *   Route::get('/launcher/me', [LauncherAuthController::class, 'me']);
 */
class LauncherAuthController extends Controller
{
    /**
     * Опрос статуса привязки по токену — лаунчер дёргает это раз в 2 секунды,
     * пока пользователь не нажмёт Start в Telegram.
     */
    public function status(Request $request, string $token)
    {
        $telegramId = Cache::get('launcher_token:' . $token);

        if (! $telegramId) {
            return response()->json(['linked' => false]);
        }

        $user = TelegramUser::query()->where('telegram_id', $telegramId)->first();

        if (! $user) {
            return response()->json(['linked' => false]);
        }

        return response()->json([
            'linked' => true,
            'telegram_id' => (string) $user->telegram_id,
            'username' => $user->username,
            'tfg_player_id' => $user->tfg_player_id,
        ]);
    }

    /**
     * Лаунчер при старте (и перед каждым запуском игры) проверяет, что
     * токен ещё действителен и не был отозван вручную.
     */
    public function me(Request $request)
    {
        $token = $request->bearerToken();
        $telegramId = $token ? Cache::get('launcher_token:' . $token) : null;

        if (! $telegramId) {
            return response()->json(['message' => 'Токен недействителен'], 401);
        }

        $user = TelegramUser::query()->where('telegram_id', $telegramId)->first();

        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 401);
        }

        return response()->json([
            'telegram_id' => (string) $user->telegram_id,
            'username' => $user->username,
            'tfg_player_id' => $user->tfg_player_id,
        ]);
    }

    /** Опционально: отозвать доступ лаунчера вручную (например, по команде администратора в боте). */
    public static function revoke(string $token): void
    {
        Cache::forget('launcher_token:' . $token);
    }
}
