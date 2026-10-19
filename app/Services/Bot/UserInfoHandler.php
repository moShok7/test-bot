<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class UserInfoHandler
{
    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));

        if ($text === '') {
            return false;
        }

        $chatId = $message->chat->id;
        $threadId = $message->message_thread_id ?? null;

        // Показать собственный профиль.
        if ($this->isSelfCommand($text)) {
            $telegramUser = $message->from ?? null;
            $telegramId = $telegramUser->id ?? null;

            if (!$telegramId) {
                return true;
            }

            $user = $this->syncTelegramUser($telegramUser);

            $this->sendProfile(
                $chatId,
                $user,
                $telegram,
                $threadId
            );

            return true;
        }

        // Показать профиль пользователя, на сообщение которого ответили.
        if ($this->isReplyCommand($text)) {
            $reply = $message->reply_to_message ?? null;
            $target = $reply->from ?? null;
            $targetId = $target->id ?? null;

            if (!$target || !$targetId) {
                $this->sendMessage(
                    $telegram,
                    $chatId,
                    'Ответь командой «кто ты» на сообщение пользователя.',
                    $threadId
                );

                return true;
            }

            if (!empty($target->is_bot)) {
                $this->sendMessage(
                    $telegram,
                    $chatId,
                    'Нельзя показать профиль бота.',
                    $threadId
                );

                return true;
            }

            $user = $this->syncTelegramUser($target);

            $this->sendProfile(
                $chatId,
                $user,
                $telegram,
                $threadId
            );

            return true;
        }

        // Поиск по username.
        $username = $this->extractUsernameSearch($text);

        if ($username !== null) {
            $user = TelegramUser::query()
                ->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
                ->first();

            if (!$user) {
                $this->sendMessage(
                    $telegram,
                    $chatId,
                    "Пользователь @{$username} не найден в базе бота.",
                    $threadId
                );

                return true;
            }

            $this->sendProfile(
                $chatId,
                $user,
                $telegram,
                $threadId
            );

            return true;
        }

        return false;
    }

    private function isSelfCommand(string $text): bool
    {
        $text = mb_strtolower(trim($text));

        $commands = [
            'кто я',
            '/whoami',
        ];

        $botUsername = trim(
            (string) config('services.telegram-bot.username', '')
        );

        if ($botUsername !== '') {
            $commands[] = '/whoami@' . mb_strtolower(
                ltrim($botUsername, '@')
            );
        }

        return in_array($text, $commands, true);
    }

    private function isReplyCommand(string $text): bool
    {
        return in_array(
            mb_strtolower(trim($text)),
            ['кто ты', '/whoyou'],
            true
        );
    }

    private function extractUsernameSearch(string $text): ?string
    {
        if (!preg_match(
            '/^(?:кто|\/who|\/whois)\s+(@?[a-zA-Z0-9_]{5,32})$/iu',
            trim($text),
            $matches
        )) {
            return null;
        }

        $username = ltrim($matches[1], '@');

        if (in_array(mb_strtolower($username), ['я', 'ты'], true)) {
            return null;
        }

        return $username;
    }

    private function syncTelegramUser($from): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_id' => $from->id],
            [
                'username' => $from->username ?? null,
                'first_name' => $from->first_name ?? 'Пользователь',
            ]
        );
    }

    private function sendProfile(
        $chatId,
        TelegramUser $user,
        Api $telegram,
        $threadId = null
    ): void {
        $displayName = $user->username
            ? '@' . $user->username
            : ($user->first_name ?: 'Пользователь');

        $lines = [
            '<b>👤 Профиль игрока</b>',
            '',
            '<b>Имя:</b> ' . $this->escape($displayName),
            '<b>Telegram ID:</b> <code>' . (int) $user->telegram_id . '</code>',
            '<b>📝 О себе:</b> ' .
                (trim((string) $user->bio) !== ''
                    ? $this->escape((string) $user->bio)
                    : 'Не заполнено'),
            '<b>🖥 Сервер:</b> ' .
                (in_array($user->server, ['13+', '13-'], true)
                    ? $this->escape($user->server)
                    : 'Не указан'),
        ];

        // Социальные ссылки из telegram_user_socials.
        try {
            $socials = DB::table('telegram_user_socials')
                ->where('telegram_user_id', $user->id)
                ->get();

            
            foreach ($socials as $social) {
                $platform = $social->platform ?? null;
                $url = trim((string) ($social->url ?? ''));

                if (!$platform || $url === '') {
                    continue;
                }

                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }

                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                if (!in_array($scheme, ['http', 'https'], true)) {
                    continue;
                }

                $label = $this->socialLabel($platform);

                $lines[] = '<b>' . $this->escape($label) . ':</b> '
                    . '<a href="' . $this->escape($url) . '">'
                    . $this->escape($url)
                    . '</a>';
            }

        } catch (Throwable $e) {
            Log::warning('UserInfoHandler: failed to load social links', [
                'telegram_id' => $user->telegram_id,
                'error' => $e->getMessage(),
            ]);
        }

        $caption = implode("\n", $lines);

        // Если скин загружен, отправляем сам скриншот.
        // Текст «🎮 Скин: Загружен» в профиле не показываем.
        if (!empty($user->game_skin)) {
            try {
                $photoParams = [
                    'chat_id' => $chatId,
                    'photo' => $user->game_skin,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ];

                if ($threadId !== null) {
                    $photoParams['message_thread_id'] = $threadId;
                }

                $telegram->sendPhoto($photoParams);

                return;
            } catch (Throwable $e) {
                Log::warning('UserInfoHandler: failed to send game skin', [
                    'telegram_id' => $user->telegram_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->sendMessage(
            $telegram,
            $chatId,
            $caption,
            $threadId,
            true
        );
    }

    private function sendMessage(
        Api $telegram,
        $chatId,
        string $text,
        $threadId = null,
        bool $html = false
    ): void {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($html) {
            $params['parse_mode'] = 'HTML';
        }

        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        $telegram->sendMessage($params);
    }

    private function socialLabel(string $platform): string
    {
        $labels = [
            'instagram' => 'Instagram',
            'telegram' => 'Telegram',
            'telegram_channel' => 'Telegram',
            'tiktok' => 'TikTok',
            'youtube' => 'YouTube',
            'twitch' => 'Twitch',
            'other_link' => 'Другая ссылка',
        ];

        return $labels[$platform] ?? $platform;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}