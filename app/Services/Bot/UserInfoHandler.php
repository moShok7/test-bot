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

        // Профиль отправителя.
        if ($this->isSelfCommand($text)) {
            $telegramId = $message->from->id ?? null;

            if (!$telegramId) {
                return true;
            }

            $user = $this->syncTelegramUser($message->from);

            $this->sendProfile(
                $chatId,
                $user,
                $telegram,
                $threadId
            );

            return true;
        }

        // Профиль пользователя, на сообщение которого ответили.
        if ($this->isReplyCommand($text)) {
            $reply = $message->reply_to_message ?? null;
            $target = $reply->from ?? null;
            $targetTelegramId = $target->id ?? null;

            if (!$target || !$targetTelegramId) {
                $this->sendMessage(
                    $telegram,
                    $chatId,
                    'Ответь командой «кто ты» на сообщение пользователя.',
                    $threadId
                );

                return true;
            }

            // Не показываем профиль самого бота.
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
                ->whereRaw(
                    'LOWER(username) = ?',
                    [mb_strtolower($username)]
                )
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
        return in_array(mb_strtolower(trim($text)), [
            'кто ты',
            '/whoyou',
        ], true);
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
        $telegramId = $from->id ?? null;

        return TelegramUser::updateOrCreate(
            ['telegram_id' => $telegramId],
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
            '<b>🎮 Скин:</b> ' . ($user->game_skin ? 'Загружен' : 'Не загружен'),
            '<b>📝 О себе:</b> ' . (
                trim((string) $user->bio) !== ''
                    ? $this->escape((string) $user->bio)
                    : 'Не заполнено'
            ),
            '<b>🖥 Сервер:</b> ' . (
                in_array($user->server, ['13+', '13-'], true)
                    ? $this->escape($user->server)
                    : 'Не указан'
            ),
        ];

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

                    $label = $this->socialLabel(
                        (string) ($social->platform ?? '')
                    );

                    $scheme = strtolower(
                        (string) parse_url($url, PHP_URL_SCHEME)
                    );

                    if (
                        filter_var($url, FILTER_VALIDATE_URL) &&
                        in_array($scheme, ['http', 'https'], true)
                    ) {
                        $lines[] = $this->escape($label)
                            . ': <a href="'
                            . $this->escape($url)
                            . '">Открыть ссылку</a>';
                    } else {
                        $lines[] = $this->escape($label)
                            . ': '
                            . $this->escape($url);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error('UserInfoHandler: failed to load socials', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        $caption = implode("\n", $lines);

        $params = [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        // Отправляем фотографию с подписью, если скин сохранён.
        if ($user->game_skin) {
            $params['photo'] = $user->game_skin;
            $params['caption'] = $caption;

            if ($threadId !== null) {
                $params['message_thread_id'] = $threadId;
            }

            try {
                $telegram->sendPhoto($params);
                return;
            } catch (Throwable $e) {
                Log::warning('UserInfoHandler: failed to send skin photo', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                // Если Telegram не принял фото, отправляем текстовый профиль.
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
        return [
            'instagram' => '📸 Instagram',
            'telegram' => '✈️ Telegram-канал',
            'tiktok' => '🎵 TikTok',
            'youtube' => '▶️ YouTube',
            'twitch' => '🎮 Twitch',
            'other' => '🔗 Другая ссылка',
        ][$platform] ?? $platform;
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
