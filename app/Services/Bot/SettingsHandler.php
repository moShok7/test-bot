<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;

class SettingsHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');
        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            // Не перехватываем команды, если пользователь ещё не создан.
            return false;
        }

        /*
         * Обработка ввода, ожидаемого в текущем состоянии профиля.
         */
        if ($user->profile_editing) {
            if ($text === '❌ Отмена') {
                $user->update([
                    'profile_editing' => null,
                    'profile_social_platform' => null,
                ]);

                $user->refresh();
                $this->showProfile($message, $telegram, $user);
                return true;
            }

            $state = $user->profile_editing;

            if ($state === 'bio') {
                if ($text === '') {
                    $this->prompt($message, $telegram, 'Описание не может быть пустым. Отправь текст или нажми «❌ Отмена».');
                    return true;
                }

                $user->update([
                    'bio' => mb_substr($text, 0, 500),
                    'profile_editing' => null,
                ]);

                $user->refresh();
                $this->showProfile($message, $telegram, $user);
                return true;
            }

            if ($state === 'game_skin') {
                if ($text === '') {
                    $this->prompt($message, $telegram, 'Название скина не может быть пустым. Отправь название или нажми «❌ Отмена».');
                    return true;
                }

                $user->update([
                    'game_skin' => mb_substr($text, 0, 100),
                    'profile_editing' => null,
                ]);

                $user->refresh();
                $this->showProfile($message, $telegram, $user);
                return true;
            }

            if ($state === 'server') {
                if (!in_array($text, ['13+', '13-'], true)) {
                    $telegram->sendMessage([
                        'chat_id' => $message->chat->id,
                        'text' => 'Выбери сервер с помощью кнопки: 13+ или 13-.',
                        'reply_markup' => $this->serverKeyboard(),
                    ]);
                    return true;
                }

                $user->update([
                    'server' => $text,
                    'profile_editing' => null,
                ]);

                $user->refresh();
                $this->showProfile($message, $telegram, $user);
                return true;
            }

            if ($state === 'social_url') {
                $platform = $user->profile_social_platform;
                $url = $this->normalizeSocialUrl($platform, $text);

                if (!$url) {
                    $telegram->sendMessage([
                        'chat_id' => $message->chat->id,
                        'text' => 'Не удалось распознать ник или ссылку. Проверь данные и отправь ещё раз либо нажми «❌ Отмена».',
                        'reply_markup' => $this->cancelKeyboard(),
                    ]);
                    return true;
                }

                $now = now();

                DB::table('telegram_user_socials')->updateOrInsert(
                    [
                        'telegram_user_id' => $user->id,
                        'platform' => $platform,
                    ],
                    [
                        'url' => $url,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );

                $user->update([
                    'profile_editing' => null,
                    'profile_social_platform' => null,
                ]);

                $this->showSocials($message, $telegram, $user);
                return true;
            }

            // Не даём случайному сообщению уйти в другие обработчики,
            // пока пользователь находится в режиме редактирования.
            return true;
        }

        /*
         * Профиль игрока.
         */
        if ($text === '👤 Профиль') {
            $this->showProfile($message, $telegram, $user);
            return true;
        }

        if ($text === '⚙️ Редактировать профиль') {
            $this->showProfileEditor($message, $telegram);
            return true;
        }

        if ($text === '🎮 Изменить скин') {
            $this->beginProfileEdit(
                $message,
                $telegram,
                $user,
                'game_skin',
                'Отправь название своего игрового скина. Пока что оно сохраняется как текст.'
            );
            return true;
        }

        if ($text === '📝 Изменить описание') {
            $this->beginProfileEdit(
                $message,
                $telegram,
                $user,
                'bio',
                'Напиши немного о себе. Максимум 500 символов.'
            );
            return true;
        }

        if ($text === '🖥 Сервер') {
            $user->update(['profile_editing' => 'server']);

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => 'Выбери сервер: 13+ или 13-.',
                'reply_markup' => $this->serverKeyboard(),
            ]);
            return true;
        }

        if ($text === '📱 Социальные сети') {
            $this->showSocials($message, $telegram, $user);
            return true;
        }

        $socialButtons = [
            '📸 Instagram' => 'instagram',
            '✈️ Telegram-канал' => 'telegram',
            '🎵 TikTok' => 'tiktok',
            '▶️ YouTube' => 'youtube',
            '🎮 Twitch' => 'twitch',
        ];

        if (isset($socialButtons[$text])) {
            $platform = $socialButtons[$text];

            $user->update([
                'profile_editing' => 'social_url',
                'profile_social_platform' => $platform,
            ]);

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => "Отправь ник или ссылку для {$text}. Чтобы отменить, нажми «❌ Отмена».",
                'reply_markup' => $this->cancelKeyboard(),
            ]);
            return true;
        }

        if ($text === '➕ Добавить другую ссылку') {
            $user->update([
                'profile_editing' => 'social_url',
                'profile_social_platform' => 'other',
            ]);

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => 'Отправь полную ссылку, начинающуюся с https://. Для отмены нажми «❌ Отмена».',
                'reply_markup' => $this->cancelKeyboard(),
            ]);
            return true;
        }

        if ($text === '🗑 Удалить соцсеть') {
            $this->showSocialDeletion($message, $telegram, $user);
            return true;
        }

        if (str_starts_with($text, '🗑 ')) {
            $platform = trim(mb_substr($text, 2));
            $allowed = ['instagram', 'telegram', 'tiktok', 'youtube', 'twitch', 'other'];

            if (in_array($platform, $allowed, true)) {
                DB::table('telegram_user_socials')
                    ->where('telegram_user_id', $user->id)
                    ->where('platform', $platform)
                    ->delete();

                $this->showSocials($message, $telegram, $user);
                return true;
            }
        }

        if ($text === '⬅️ К профилю') {
            $this->showProfile($message, $telegram, $user);
            return true;
        }

        /*
         * Настройки уведомлений.
         */
        if ($text === '⚙️ Настройки') {
            $this->showSettings($message, $telegram, $user);
            return true;
        }

        if ($text === '🔔 Уведомления') {
            $this->showNotifications($message, $telegram, $user);
            return true;
        }

        if ($text === '💬 Уведомления чата') {
            $this->showChatNotifications($message, $telegram, $user);
            return true;
        }

        if ($text === '🟢 Включить уведомления') {
            $user->update(['chat_notifications' => true]);

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => "💬 Уведомления чата\n\n🟢 Уведомления включены.",
                'reply_markup' => $this->chatNotificationKeyboard(),
            ]);
            return true;
        }

        if ($text === '🔴 Выключить уведомления') {
            $user->update(['chat_notifications' => false]);

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => "💬 Уведомления чата\n\n🔴 Уведомления выключены.",
                'reply_markup' => $this->chatNotificationKeyboard(),
            ]);
            return true;
        }

        if ($text === '❌ Отмена') {
            $this->showProfile($message, $telegram, $user);
            return true;
        }

        if ($text === '⬅️ Назад') {
            $this->showMainMenu($message, $telegram);
            return true;
        }

        return false;
    }

    private function showProfile($message, $telegram, $user): void
    {
        $name = $user->username
            ? '@' . $user->username
            : ($user->first_name ?? 'Пользователь');

        $lines = [
            '👤 Профиль игрока',
            '',
            "Игрок: {$name}",
            '🎮 Скин: ' . ($user->game_skin ?: 'Не указан'),
            '📝 О себе: ' . ($user->bio ?: 'Не заполнено'),
            '🖥 Сервер: ' . ($user->server ?: 'Не указана'),
        ];

        $socials = DB::table('telegram_user_socials')
            ->where('telegram_user_id', $user->id)
            ->orderBy('id')
            ->get();

        if ($socials->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '📱 Социальные сети';

            foreach ($socials as $social) {
                $lines[] = $this->socialLabel($social->platform) . ': ' . $social->url;
            }
        }

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => implode("\n", $lines),
            'reply_markup' => $this->profileKeyboard(),
        ]);
    }

    private function profileKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [['text' => '⚙️ Редактировать профиль']],
                [['text' => '⬅️ Назад']],
            ],
            'resize_keyboard' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function showProfileEditor($message, $telegram): void
    {
        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => '⚙️ Редактирование профиля',
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '🎮 Изменить скин']],
                    [['text' => '📝 Изменить описание']],
                    [['text' => '🖥 Сервер']],
                    [['text' => '📱 Социальные сети']],
                    [['text' => '⬅️ К профилю']],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function beginProfileEdit($message, $telegram, $user, string $field, string $prompt): void
    {
        $user->update(['profile_editing' => $field]);

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => $prompt,
            'reply_markup' => $this->cancelKeyboard(),
        ]);
    }

    private function serverKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [['text' => '13+'], ['text' => '13-']],
                [['text' => '❌ Отмена']],
            ],
            'resize_keyboard' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function cancelKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [['text' => '❌ Отмена']],
            ],
            'resize_keyboard' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function showSocials($message, $telegram, $user): void
    {
        $socials = DB::table('telegram_user_socials')
            ->where('telegram_user_id', $user->id)
            ->get()
            ->keyBy('platform');

        $platforms = [
            'instagram' => '📸 Instagram',
            'telegram' => '✈️ Telegram-канал',
            'tiktok' => '🎵 TikTok',
            'youtube' => '▶️ YouTube',
            'twitch' => '🎮 Twitch',
        ];

        $lines = ['📱 Социальные сети', '', 'Выбери сеть, чтобы добавить или обновить ссылку:'];
        foreach ($platforms as $key => $label) {
            $lines[] = $label . ' — ' . (isset($socials[$key]) ? 'добавлено' : 'не указано');
        }

        $keyboard = [];
        foreach ($platforms as $label) {
            $keyboard[] = [['text' => $label]];
        }

        $keyboard[] = [['text' => '➕ Добавить другую ссылку']];
        if ($socials->isNotEmpty()) {
            $keyboard[] = [['text' => '🗑 Удалить соцсеть']];
        }
        $keyboard[] = [['text' => '⬅️ К профилю']];

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => implode("\n", $lines),
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function showSocialDeletion($message, $telegram, $user): void
    {
        $socials = DB::table('telegram_user_socials')
            ->where('telegram_user_id', $user->id)
            ->get();

        if ($socials->isEmpty()) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => 'У тебя пока нет добавленных социальных сетей.',
                'reply_markup' => $this->socialDeletionKeyboard(),
            ]);
            return;
        }

        $keyboard = [];
        foreach ($socials as $social) {
            $keyboard[] = [[
                'text' => '🗑 ' . $social->platform,
            ]];
        }
        $keyboard[] = [['text' => '⬅️ К профилю']];

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => 'Выбери социальную сеть для удаления.',
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function socialDeletionKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [['text' => '⬅️ К профилю']],
            ],
            'resize_keyboard' => true,
        ], JSON_UNESCAPED_UNICODE);
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

    private function normalizeSocialUrl(?string $platform, string $value): ?string
    {
        $value = trim($value);

        if (!$platform || $value === '') {
            return null;
        }

        if ($platform === 'instagram') {
            $value = ltrim($value, '@');

            if (preg_match('/^[a-zA-Z0-9._]{1,30}$/', $value)) {
                return 'https://www.instagram.com/' . $value . '/';
            }

            if (preg_match('~^https?://(www\.)?instagram\.com/[a-zA-Z0-9._]+/?(\?.*)?$~i', $value)) {
                return $value;
            }

            return null;
        }

        if ($platform === 'telegram') {
            $value = ltrim($value, '@');

            if (preg_match('/^[a-zA-Z0-9_]{5,32}$/', $value)) {
                return 'https://t.me/' . $value;
            }

            if (preg_match('~^https?://t\.me/[a-zA-Z0-9_]{5,32}/?$~i', $value)) {
                return $value;
            }

            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if (!in_array($scheme, ['http', 'https'], true) || !$host) {
            return null;
        }

        $allowedHosts = [
            'tiktok' => ['tiktok.com', 'www.tiktok.com'],
            'youtube' => ['youtube.com', 'www.youtube.com', 'youtu.be', 'm.youtube.com'],
            'twitch' => ['twitch.tv', 'www.twitch.tv'],
        ];

        if (isset($allowedHosts[$platform]) && !in_array($host, $allowedHosts[$platform], true)) {
            return null;
        }

        if ($platform === 'other' && $scheme !== 'https') {
            return null;
        }

        return $value;
    }

    private function prompt($message, $telegram, string $text): void
    {
        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => $text,
            'reply_markup' => $this->cancelKeyboard(),
        ]);
    }

    private function showSettings($message, $telegram, $user): void
    {
        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => "⚙️ Настройки\n\nВыберите нужную настройку:",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '🔔 Уведомления']],
                    [['text' => '👤 Профиль']],
                    [['text' => '⬅️ Назад']],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function showNotifications($message, $telegram, $user): void
    {
        $status = $user->chat_notifications ? '🟢 Включены' : '🔴 Выключены';

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => "🔔 Уведомления\n\n💬 Уведомления чата: {$status}",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '💬 Уведомления чата']],
                    [['text' => '⬅️ Назад']],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function showChatNotifications($message, $telegram, $user): void
    {
        $status = $user->chat_notifications ? '🟢 Включены' : '🔴 Выключены';

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => "💬 Уведомления чата\n\nСтатус: {$status}\n\nПолучать уведомления о новых сообщениях в чате?",
            'reply_markup' => $this->chatNotificationKeyboard(),
        ]);
    }

    private function chatNotificationKeyboard(): string
    {
        return json_encode([
            'keyboard' => [
                [['text' => '🟢 Включить уведомления']],
                [['text' => '🔴 Выключить уведомления']],
                [['text' => '⬅️ Назад']],
            ],
            'resize_keyboard' => true,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function showMainMenu($message, $telegram): void
    {
        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' => '🎮 Игровое меню',
            'reply_markup' => json_encode([
                'keyboard' => [
                    [
                        ['text' => '➕ Создать лобби'],
                        ['text' => '🔍 Найти лобби'],
                    ],
                    [['text' => '🎮 Моё лобби']],
                    [['text' => '👤 Профиль']],
                    [['text' => '⚙️ Настройки']],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
