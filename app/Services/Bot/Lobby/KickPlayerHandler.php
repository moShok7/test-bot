<?php

namespace App\Services\Bot\Lobby;

use App\Models\TelegramUser;
use App\Models\Lobby;
use App\Models\LobbyPlayer;

class KickPlayerHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');
        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $chatId = $message->chat->id;

        /*
        |--------------------------------------------------------------------------
        | Назад
        |--------------------------------------------------------------------------
        */

        if ($text === '⬅️ Назад') {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "👑 Панель хоста\n\nВыберите действие:",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            ['text' => '👥 Игроки']
                        ],
                        [
                            ['text' => '❌ Кикнуть игрока']
                        ],
                        [
                            ['text' => '▶️ Начать игру']
                        ],
                        [
                            ['text' => '✏️ Изменить код']
                        ],
                        [
                            ['text' => '🚪 Выйти из лобби']
                        ],
                        [
                            ['text' => '⬅️ Главное меню']
                        ],
                    ],
                    'resize_keyboard' => true
                ])
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем TelegramUser хоста
        |--------------------------------------------------------------------------
        */

        $host = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$host) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем активное лобби хоста
        |--------------------------------------------------------------------------
        */

        $lobby = Lobby::where(
            'creator_id',
            $host->id
        )
        ->where(
            'status',
            'waiting'
        )
        ->with([
            'players.telegramUser'
        ])
        ->first();

        /*
        |--------------------------------------------------------------------------
        | Кнопка "❌ Кикнуть игрока"
        |--------------------------------------------------------------------------
        */

        if ($text === '❌ Кикнуть игрока') {

            if (!$lobby) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Вы не являетесь хостом активного лобби."
                ]);

                return true;
            }

            $keyboard = [];

            foreach ($lobby->players as $player) {

                /*
                |--------------------------------------------------------------------------
                | Хоста нельзя кикнуть
                |--------------------------------------------------------------------------
                */

                if (
                    $player->telegram_user_id ==
                    $host->id
                ) {
                    continue;
                }

                $telegramUser = $player->telegramUser;

                if (!$telegramUser) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Имя игрока
                |--------------------------------------------------------------------------
                */

                $name = $telegramUser->first_name;

                if (!$name) {
                    $name = 'Игрок';
                }

                /*
                |--------------------------------------------------------------------------
                | Username
                |--------------------------------------------------------------------------
                */

                if ($telegramUser->username) {
                    $name .= ' @' . $telegramUser->username;
                }

                /*
                |--------------------------------------------------------------------------
                | ВАЖНО:
                | Передаём Telegram ID в текст кнопки.
                | Поэтому одинаковые имена не создают проблему.
                |--------------------------------------------------------------------------
                */

                $keyboard[] = [
                    [
                        'text' =>
                            "❌ {$name} [{$telegramUser->telegram_id}]"
                    ]
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Если кроме хоста никого нет
            |--------------------------------------------------------------------------
            */

            if (empty($keyboard)) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "👥 В лобби пока нет игроков, которых можно удалить.",
                    'reply_markup' => json_encode([
                        'keyboard' => [
                            [
                                ['text' => '⬅️ Назад']
                            ]
                        ],
                        'resize_keyboard' => true
                    ])
                ]);

                return true;
            }

            $keyboard[] = [
                [
                    'text' => '⬅️ Назад'
                ]
            ];

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "👥 Выберите игрока для удаления:",
                'reply_markup' => json_encode([
                    'keyboard' => $keyboard,
                    'resize_keyboard' => true
                ])
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Выбран игрок
        |--------------------------------------------------------------------------
        |
        | Формат:
        | ❌ Shohjahon [123456789]
        |
        */

        if (
            str_starts_with($text, '❌ ')
            &&
            preg_match('/\[(\d+)\]$/', $text, $matches)
        ) {

            $kickedTelegramId = $matches[1];

            /*
            |--------------------------------------------------------------------------
            | Получаем TelegramUser игрока
            |--------------------------------------------------------------------------
            */

            $kickedUser = TelegramUser::where(
                'telegram_id',
                $kickedTelegramId
            )->first();

            if (!$kickedUser) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Пользователь не найден."
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Получаем актуальное лобби хоста
            |--------------------------------------------------------------------------
            */

            $lobby = Lobby::where(
                'creator_id',
                $host->id
            )
            ->where(
                'status',
                'waiting'
            )
            ->first();

            if (!$lobby) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Активное лобби не найдено."
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Хост не может кикнуть самого себя
            |--------------------------------------------------------------------------
            */

            if (
                $kickedUser->id ==
                $host->id
            ) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Нельзя кикнуть самого себя."
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Ищем игрока в этом лобби
            |--------------------------------------------------------------------------
            */

            $player = LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )
            ->where(
                'telegram_user_id',
                $kickedUser->id
            )
            ->first();

            if (!$player) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Этот игрок уже не находится в лобби."
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Имя игрока
            |--------------------------------------------------------------------------
            */

            $playerName =
                $kickedUser->first_name
                ?: 'Игрок';

            if ($kickedUser->username) {
                $playerName .=
                    ' @' . $kickedUser->username;
            }

            /*
            |--------------------------------------------------------------------------
            | Удаляем игрока
            |--------------------------------------------------------------------------
            */

            $player->delete();

            /*
            |--------------------------------------------------------------------------
            | Количество игроков
            |--------------------------------------------------------------------------
            */

            $playersCount = LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )->count();

            /*
            |--------------------------------------------------------------------------
            | Уведомляем кикнутого игрока
            |--------------------------------------------------------------------------
            */

            try {

                $telegram->sendMessage([
                    'chat_id' =>
                        $kickedUser->telegram_id,

                    'text' =>
                        "❌ Вы были исключены из лобби.\n\n" .
                        "👑 Хост удалил вас из лобби #{$lobby->id}."
                ]);

            } catch (\Throwable $e) {

                \Log::error(
                    'Kick notification error',
                    [
                        'message' => $e->getMessage()
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Уведомляем остальных игроков
            |--------------------------------------------------------------------------
            */

            $remainingPlayers = LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )
            ->with('telegramUser')
            ->get();

            foreach ($remainingPlayers as $remainingPlayer) {

                $remainingUser =
                    $remainingPlayer->telegramUser;

                if (!$remainingUser) {
                    continue;
                }

                try {

                    $telegram->sendMessage([
                        'chat_id' =>
                            $remainingUser->telegram_id,

                        'text' =>
                            "👢 Игрок исключён из лобби!\n\n" .
                            "👤 {$playerName}\n" .
                            "🎮 Лобби #{$lobby->id}\n" .
                            "👥 Игроки: {$playersCount}/{$lobby->max_players}"
                    ]);

                } catch (\Throwable $e) {

                    \Log::error(
                        'Kick player notification error',
                        [
                            'message' => $e->getMessage()
                        ]
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Сообщаем хосту
            |--------------------------------------------------------------------------
            */

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "✅ Игрок {$playerName} удалён из лобби.\n\n" .
                    "👥 Игроки: {$playersCount}/{$lobby->max_players}"
            ]);

            return true;
        }

        return false;
    }
}