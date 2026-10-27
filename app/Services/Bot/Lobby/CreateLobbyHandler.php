<?php

namespace App\Services\Bot\Lobby;

use App\Jobs\SendLobbyNotificationsJob;
use App\Models\TelegramUser;
use App\Models\BotSession;
use App\Models\Lobby;
use App\Models\LobbyPlayer;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class CreateLobbyHandler
{
    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));
        $chatId = $message->chat->id ?? null;
        $telegramId = $message->from->id ?? null;

        if (!$chatId || !$telegramId) {
            return false;
        }

        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем сессию пользователя
        |--------------------------------------------------------------------------
        */

        $session = BotSession::where(
            'telegram_user_id',
            $user->id
        )->first();

        /*
        |--------------------------------------------------------------------------
        | Премьерное лобби обрабатывает отдельный handler
        |--------------------------------------------------------------------------
        */

        if ($text === '🎬 Создать премьерное лобби') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Главное меню создания лобби
        |--------------------------------------------------------------------------
        */

        if ($text === '➕ Создать лобби') {
            $already = Lobby::where('creator_id', $user->id)
                ->where('status', 'waiting')
                ->exists();

            if ($already) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⚠️ У вас уже есть ожидающее лобби.\n\n"
                        . 'Сначала завершите или удалите его.',
                    'reply_markup' => json_encode([
                        'keyboard' => [
                            [['text' => '🎮 Моё лобби']],
                            [['text' => '⬅️ Главное меню']],
                        ],
                        'resize_keyboard' => true,
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                return true;
            }

            BotSession::updateOrCreate(
                ['telegram_user_id' => $user->id],
                [
                    'step' => 'lobby_type',
                    'data' => json_encode([], JSON_UNESCAPED_UNICODE),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "➕ Создание лобби\n\nВыберите тип:",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '🎮 Обычное лобби']],
                        [['text' => '🎬 Создать премьерное лобби']],
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Выбор типа лобби
        |--------------------------------------------------------------------------
        */

        if ($session && $session->step === 'lobby_type') {
            if ($text === '🎮 Обычное лобби') {
                $session->step = 'lobby_code';
                $session->data = json_encode([], JSON_UNESCAPED_UNICODE);
                $session->save();

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "🎮 Создайте комнату в игре.\n\n"
                        . "После этого отправьте код комнаты.\n\n"
                        . "🔑 Код должен содержать ровно 6 символов.\n"
                        . "Разрешены только английские буквы и цифры.\n"
                        . "⏱ У вас есть 5 минут.\n\n"
                        . 'Или нажмите «⬅️ Главное меню».',
                    'reply_markup' => json_encode([
                        'keyboard' => [
                            [['text' => '⬅️ Главное меню']],
                        ],
                        'resize_keyboard' => true,
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                return true;
            }

            if ($text === '🎬 Создать премьерное лобби') {
                return false;
            }

            if ($text === '⬅️ Главное меню') {
                $session->delete();

                return false;
            }

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Выберите тип лобби с помощью кнопок ниже.',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '🎮 Обычное лобби']],
                        [['text' => '🎬 Создать премьерное лобби']],
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Обычное лобби: ввод кода комнаты
        |--------------------------------------------------------------------------
        */

        if (!$session || $session->step !== 'lobby_code') {
            return false;
        }

        if ($text === '⬅️ Главное меню') {
            $session->delete();

            return false;
        }

        if (!preg_match('/^[A-Za-z0-9]{6}$/', $text)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Неверный формат кода.\n\n"
                    . "Код должен содержать ровно 6 символов: "
                    . 'только английские буквы и цифры.',
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем, нет ли уже ожидающего лобби
        |--------------------------------------------------------------------------
        */

        $already = Lobby::where('creator_id', $user->id)
            ->where('status', 'waiting')
            ->exists();

        if ($already) {
            $session->delete();

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ У вас уже есть ожидающее лобби.',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '🎮 Моё лобби']],
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём обычное лобби и запускаем рассылку
        |--------------------------------------------------------------------------
        */

        try {
            $lobby = Lobby::create([
                'creator_id' => $user->id,
                'game_room_code' => strtoupper($text),
                'status' => 'waiting',
            ]);

            LobbyPlayer::firstOrCreate([
                'lobby_id' => $lobby->id,
                'telegram_user_id' => $user->id,
            ]);

            $creatorName = $user->username
                ? '@' . $user->username
                : ($user->first_name ?: 'Игрок');

            SendLobbyNotificationsJob::dispatch(
                lobbyId: (int) $lobby->id,
                creatorTelegramId: (int) $user->telegram_id,
                creatorName: (string) $creatorName,
                count: 100,
            )->onQueue('telegram');

            $session->delete();

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "✅ Обычное лобби создано!\n\n"
                    . "🔑 Код комнаты: {$lobby->game_room_code}\n"
                    . "🆔 Лобби: #{$lobby->id}\n\n"
                    . '📢 Запущена рассылка игрокам.',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '❌ Удалить лобби']],
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Create lobby error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    '❌ Не удалось создать лобби. '
                    . 'Проверьте настройки базы данных и попробуйте снова.',
            ]);

            return true;
        }
    }
}
