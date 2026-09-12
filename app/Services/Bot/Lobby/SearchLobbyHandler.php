<?php

namespace App\Services\Bot\Lobby;

use App\Models\BotSession;
use App\Models\TelegramUser;
use App\Models\Lobby;
use App\Models\LobbyPlayer;

class SearchLobbyHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        /*
        |--------------------------------------------------------------------------
        | Обрабатываем только кнопки поиска
        |--------------------------------------------------------------------------
        */

        if (
            $text !== '🔍 Найти лобби' &&
            $text !== '🔄 Обновить поиск'
        ) {
            return false;
        }

        $chatId =
            $message->chat->id ?? null;

        $telegramId =
            $message->from->id ?? null;

        if (!$telegramId || !$chatId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем TelegramUser
        |--------------------------------------------------------------------------
        */

        $telegramUser = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$telegramUser) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Удаляем сессию создания лобби
        |--------------------------------------------------------------------------
        |
        | ВАЖНО:
        | bot_sessions.telegram_user_id =
        | telegram_users.id
        |
        | Поэтому используем $telegramUser->id,
        | а НЕ $telegramId.
        |
        */

        BotSession::where(
            'telegram_user_id',
            $telegramUser->id
        )->delete();

        $telegramUserId =
            $telegramUser->id;

        /*
        |--------------------------------------------------------------------------
        | Проверяем, не находится ли пользователь уже
        | в активном лобби
        |--------------------------------------------------------------------------
        */

        $already = LobbyPlayer::where(
            'telegram_user_id',
            $telegramUserId
        )
        ->whereHas(
            'lobby',
            function ($query) {
                $query->whereIn(
                    'status',
                    [
                        'waiting',
                        'playing'
                    ]
                );
            }
        )
        ->exists();

        if ($already) {

            $telegram->sendMessage([
                'chat_id' =>
                    $chatId,

                'text' =>
                    "⚠️ Вы уже состоите в активном лобби.\n\n" .
                    "Сначала выйдите из него через 🎮 Моё лобби.",

                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            [
                                'text' =>
                                    '🎮 Моё лобби'
                            ]
                        ],

                        [
                            [
                                'text' =>
                                    '⬅️ Главное меню'
                            ]
                        ]
                    ],

                    'resize_keyboard' => true
                ])
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем доступные лобби
        |--------------------------------------------------------------------------
        */

        $lobbies = Lobby::where(
            'status',
            'waiting'
        )
        ->where(
            'creator_id',
            '!=',
            $telegramUserId
        )
        ->with('creator')
        ->withCount('players')
        ->latest()
        ->limit(10)
        ->get();

        $found = false;

        /*
        |--------------------------------------------------------------------------
        | Показываем лобби
        |--------------------------------------------------------------------------
        */

        foreach ($lobbies as $lobby) {

            /*
            |--------------------------------------------------------------------------
            | Пропускаем заполненные
            |--------------------------------------------------------------------------
            */

            if (
                $lobby->players_count >=
                $lobby->max_players
            ) {
                continue;
            }

            $found = true;

            /*
            |--------------------------------------------------------------------------
            | Имя хоста
            |--------------------------------------------------------------------------
            */

            $hostNickname = 'Игрок';

            if ($lobby->creator) {

                if (
                    !empty(
                        $lobby->creator->first_name
                    )
                ) {

                    $hostNickname =
                        $lobby->creator->first_name;

                } elseif (
                    !empty(
                        $lobby->creator->username
                    )
                ) {

                    $hostNickname =
                        '@' .
                        $lobby->creator->username;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Отправляем лобби
            |--------------------------------------------------------------------------
            */

            $telegram->sendMessage([
                'chat_id' =>
                    $chatId,

                'text' =>
                    "🎮 Лобби #{$lobby->id}\n" .
                    "👑 Хост: {$hostNickname}\n" .
                    "👥 Игроки: {$lobby->players_count}/{$lobby->max_players}\n" .
                    "⏳ Ожидание игроков",

                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' =>
                                    "🚪 Войти #{$lobby->id}",

                                'callback_data' =>
                                    "join_lobby_{$lobby->id}"
                            ]
                        ]
                    ]
                ])
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Если лобби не найдены
        |--------------------------------------------------------------------------
        */

        if (!$found) {

            $telegram->sendMessage([
                'chat_id' =>
                    $chatId,

                'text' =>
                    "😔 Сейчас нет доступных лобби."
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Меню после поиска
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' =>
                $chatId,

            'text' =>
                "🔍 Поиск завершён.\n\n" .

                (
                    $found
                        ? "Выберите подходящее лобби выше."
                        : "😔 Активных лобби пока нет.\n\n" .
                          "Вы можете создать своё лобби и ждать игроков."
                ),

            'reply_markup' => json_encode([
                'keyboard' => [

                    [
                        [
                            'text' =>
                                '🔄 Обновить поиск'
                        ]
                    ],

                    [
                        [
                            'text' =>
                                '➕ Создать лобби'
                        ]
                    ],

                    [
                        [
                            'text' =>
                                '🎮 Моё лобби'
                        ]
                    ],

                    [
                        [
                            'text' =>
                                '⬅️ Главное меню'
                        ]
                    ]
                ],

                'resize_keyboard' => true
            ])
        ]);

        return true;
    }
}
