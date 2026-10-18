<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;

class UserInfoHandler
{
    /**
     * Commands:
     *   кто я                 — show the sender's profile
     *   кто ты                — show the profile of the replied-to user
     *   кто @username         — find a registered user by username
     *   /whoami               — alias for "кто я"
     *   /who @username        — alias for "кто @username"
     *   /whois @username      — alias for "кто @username"
     */
    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));

        if ($text === '') {
            return false;
        }

        // Do not consume ordinary messages that are not one of our commands.
        if ($this->isSelfCommand($text)) {
            $telegramId = $message->from->id ?? null;

            if (!$telegramId) {
                return true;
            }

            $user = $this->syncTelegramUser($message->from);
            $this->sendProfile($message->chat->id, $user, $telegram);

            return true;
        }

        if ($this->isReplyCommand($text)) {
            $reply = $message->reply_to_message ?? null;
            $targetTelegramId = $reply->from->id ?? null;

            if (!$reply || !$targetTelegramId) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' => "Чтобы посмотреть профиль пользователя, ответь командой «кто ты» на его сообщение.",
                ]);

                return true;
            }

            $user = $this->syncTelegramUser($reply->from);
            $this->sendProfile($message->chat->id, $user, $telegram);

            return true;
        }

        $username = $this->extractUsernameSearch($text);

        if ($username !== null) {
            $user = TelegramUser::query()
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
                ->first();

            if (!$user) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' => "Пользователь @{$username} не найден в базе бота. Он должен хотя бы один раз написать боту или появиться в чате, где бот сохраняет пользователей.",
                ]);

                return true;
            }

            $this->sendProfile($message->chat->id, $user, $telegram);

            return true;
        }

        return false;
    }

    private function isSelfCommand(string $text): bool
    {
        return in_array(mb_strtolower($text), [
            'кто я',
            '/whoami',
            '/whoami@' . mb_strtolower((string) config('services.telegram-bot.username', '')),
        ], true);
    }

    private function isReplyCommand(string $text): bool
    {
        return in_array(mb_strtolower($text), [
            'кто ты',
            '/whoyou',
        ], true);
    }

    /**
     * Returns username only for supported lookup commands, otherwise null.
     */
    private function extractUsernameSearch(string $text): ?string
    {
        if (!preg_match('/^(?:кто|\/who|\/whois)(?:\s+)(@?[a-zA-Z0-9_]{5,32})$/iu', $text, $matches)) {
            return null;
        }

        $username = ltrim($matches[1], '@');

        // "кто я" and "кто ты" are handled above, not as username searches.
        if (in_array(mb_strtolower($username), ['я', 'ты'], true)) {
            return null;
        }

        return $username;
    }

    /**
     * Keep Telegram's stable numeric ID as the identity key, and refresh its public name.
     */
    private function syncTelegramUser($from): TelegramUser
    {
        $telegramId = $from->id ?? null;

        return TelegramUser::updateOrCreate(
            ['telegram_id' => $telegramId],
            [
                'username' => $from->username ?? null,
                'first_name' => $from->first_name ?? 'Пользователь',
            ]
        );
    }

    private function sendProfile($chatId, TelegramUser $user, Api $telegram): void
    {
        $displayName = $user->username
            ? '@' . $user->username
            : ($user->first_name ?: 'Пользователь');

        $lines = [
            '<b>👤 Профиль игрока</b>',
            '',
            '<b>Имя:</b> ' . $this->escape($displayName),
            '<b>Telegram ID:</b> <code>' . (int) $user->telegram_id . '</code>',
        ];

        $this->appendField($lines, '🎮 Скин', $user->game_skin ?? null);
        $this->appendField($lines, '📝 О себе', $user->bio ?? null);
        $this->appendField($lines, '🖥 Сервер', $user->server ?? null);

        // Social links are stored by SettingsHandler in telegram_user_socials.
        try {
            $socials = DB::table('telegram_user_socials')
                ->where('telegram_user_id', $user->id)
                ->orderBy('id')
                ->get();

            if ($socials->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '<b>📱 Социальные сети</b>';

                foreach ($socials as $social) {
                    $url = trim((string) ($social->url ?? ''));
                    if ($url === '') {
                        continue;
                    }

                    $label = $this->socialLabel((string) ($social->platform ?? ''));
                    $safeUrl = $this->escape($url);

                    if (filter_var($url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                        $lines[] = $this->escape($label) . ': <a href="' . $safeUrl . '">открыть ссылку</a>';
                    } else {
                        $lines[] = $this->escape($label) . ': ' . $safeUrl;
                    }
                }
            }
        } catch (\Throwable $e) {
            // A missing socials table should not prevent the basic profile from being shown.
            report($e);
        }

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    private function appendField(array &$lines, string $label, ?string $value): void
    {
        $value = trim((string) $value);

        if ($value !== '') {
            $lines[] = '<b>' . $this->escape($label) . ':</b> ' . $this->escape($value);
        }
    }

    private function socialLabel(string $platform): string
    {
        return [
            'instagram' => '📸 Instagram',
            'telegram' => '✈️ Telegram',
            'tiktok' => '🎵 TikTok',
            'youtube' => '▶️ YouTube',
            'twitch' => '🎮 Twitch',
            'other' => '🔗 Другая ссылка',
        ][$platform] ?? $platform;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
