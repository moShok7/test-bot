<?php

namespace App\Services\Bot\Admin;

use App\Models\TelegramUser;
use App\Models\TelegramUserBan;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class BanUserHandler
{
    private const ADMIN_ID = 6537808029;

    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));
        $userId = (int) ($message->from->id ?? 0);
        $chatId = $message->chat->id ?? null;

        // Поддерживаем команды с / и без него, регистр не важен.
        if (!preg_match(
            '/^\/?(ban|unban|banlist)(?:@\w+)?(?:\s+(.+))?$/iu',
            $text,
            $matches
        )) {
            return false;
        }

        // Только владелец может управлять банами.
        if ($userId !== self::ADMIN_ID) {
            return true;
        }

        $command = mb_strtolower($matches[1]);
        $target = trim($matches[2] ?? '');

        try {
            // СПИСОК БАНОВ
            if ($command === 'banlist') {
                return $this->showBanList($telegram, $chatId);
            }

            if ($target === '') {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "Укажи Telegram ID или @username.\n\n" .
                        "Примеры:\n" .
                        "БАН 123456789\n" .
                        "бан @username\n" .
                        "разбан 123456789\n" .
                        "банлист",
                ]);

                return true;
            }

            $user = $this->findUser($target);

            if (!$user) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Пользователь не найден в базе бота.',
                ]);

                return true;
            }

            // Не разрешаем забанить самого себя.
            if ((int) $user->telegram_id === self::ADMIN_ID) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '⛔ Нельзя заблокировать владельца бота.',
                ]);

                return true;
            }

            // BAN
            if ($command === 'ban') {
                $ban = TelegramUserBan::updateOrCreate(
                    [
                        'telegram_user_id' => $user->id,
                    ],
                    [
                        'banned_by' => self::ADMIN_ID,
                        'reason' => null,
                    ]
                );

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "🔨 <b>Пользователь заблокирован</b>\n\n" .
                        "ID: <code>{$user->telegram_id}</code>\n" .
                        'Username: @' .
                        ($user->username ?: 'не указан'),
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

            // UNBAN
            if ($command === 'unban') {
                $deleted = TelegramUserBan::where(
                    'telegram_user_id',
                    $user->id
                )->delete();

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $deleted
                        ? "🔓 <b>Бан снят</b>\nID: <code>{$user->telegram_id}</code>"
                        : "ℹ️ Пользователь не был заблокирован.\nID: {$user->telegram_id}",
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

        } catch (\Throwable $e) {
            Log::error('BanUserHandler error', [
                'command' => $command,
                'target' => $target,
                'error' => $e->getMessage(),
            ]);

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Ошибка обработки команды. Проверь Laravel log.',
            ]);

            return true;
        }

        return true;
    }

    /**
     * Поиск пользователя по Telegram ID или username.
     */
    private function findUser(string $target): ?TelegramUser
    {
        $target = trim($target);

        if (preg_match('/^\d+$/', $target)) {
            return TelegramUser::where(
                'telegram_id',
                (int) $target
            )->first();
        }

        $username = ltrim($target, '@');

        return TelegramUser::whereRaw(
            'LOWER(username) = ?',
            [mb_strtolower($username)]
        )->first();
    }

    /**
     * Вывод списка заблокированных пользователей.
     */
    private function showBanList(Api $telegram, $chatId): bool
    {
        $bans = TelegramUserBan::with('telegramUser')
            ->latest()
            ->get();

        $lines = [];

        foreach ($bans as $ban) {
            $user = $ban->telegramUser;

            if (!$user) {
                continue;
            }

            $username = $user->username
                ? '@' . $user->username
                : 'без username';

            $lines[] =
                "👤 {$username}\n" .
                "🆔 {$user->telegram_id}\n" .
                "📅 " . $ban->created_at->format('d.m.Y H:i');
        }

        if (empty($lines)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '📋 Список заблокированных пользователей пуст.',
            ]);

            return true;
        }

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🔨 <b>Заблокированные пользователи:</b>\n\n" .
                implode("\n\n", $lines),
            'parse_mode' => 'HTML',
        ]);

        return true;
    }
}
