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

        if (!preg_match(
            '/^\/?(ban|unban|banlist|бан|разбан|банлист)(?:@\w+)?(?:\s+(.+))?$/iu',
            $text,
            $matches
        )) {
            return false;
        }

        // Команды управления банами доступны только владельцу.
        if ($userId !== self::ADMIN_ID) {
            return true;
        }

        $rawCommand = mb_strtolower($matches[1], 'UTF-8');

        $command = match ($rawCommand) {
            'ban', 'бан' => 'ban',
            'unban', 'разбан' => 'unban',
            'banlist', 'банлист' => 'banlist',
            default => null,
        };

        $target = trim($matches[2] ?? '');

        try {
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
                        "РАЗБАН 123456789\n" .
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

            if ((int) $user->telegram_id === self::ADMIN_ID) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '⛔ Нельзя заблокировать владельца бота.',
                ]);

                return true;
            }

            if ($command === 'ban') {
                TelegramUserBan::updateOrCreate(
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
                        htmlspecialchars(
                            $user->username ?: 'не указан',
                            ENT_QUOTES | ENT_SUBSTITUTE,
                            'UTF-8'
                        ),
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

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
            [mb_strtolower($username, 'UTF-8')]
        )->first();
    }

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
                ? '@' . htmlspecialchars(
                    $user->username,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                )
                : 'без username';

            $lines[] =
                "👤 {$username}\n" .
                "🆔 {$user->telegram_id}\n" .
                "📅 " . $ban->created_at->format('d.m.Y H:i');
        }

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => empty($lines)
                ? '📋 Список заблокированных пользователей пуст.'
                : "🔨 <b>Заблокированные пользователи:</b>\n\n" .
                    implode("\n\n", $lines),
            'parse_mode' => 'HTML',
        ]);

        return true;
    }
}
