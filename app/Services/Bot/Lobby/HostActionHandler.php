<?php

namespace App\Services\Bot\Lobby;

use App\Models\TelegramUser;
use App\Models\Lobby;
use App\Models\BotSession;

class HostActionHandler
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
        | Получаем Telegram-пользователя
        |--------------------------------------------------------------------------
        |
        | GameProfile больше НЕ требуется.
        |
        */

        $telegramUser = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$telegramUser) {
            return false;
        }

        $telegramUserId = $telegramUser->id;

        /*
        |--------------------------------------------------------------------------
        | Главное меню
        |--------------------------------------------------------------------------
        */

        if ($text === '⬅️ Главное меню') {

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' => '🎮 Игровое меню',

                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            [
                                'text' => '➕ Создать лобби'
                            ],
                            [
                                'text' => '🔍 Найти лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '🎮 Моё лобби'
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
        | Получаем сессию
        |--------------------------------------------------------------------------
        */

        $session = BotSession::where(
            'telegram_user_id',
            $telegramUserId
        )->first();

        /*
        |--------------------------------------------------------------------------
        | ИЗМЕНЕНИЕ КОДА КОМНАТЫ
        |--------------------------------------------------------------------------
        */

        if (
            $session &&
            $session->step === 'change_lobby_code' &&
            $text !== '' &&
            !in_array($text, [
                '▶️ Начать игру',
                '❌ Кикнуть игрока',
                '✏️ Изменить код',
                '⬅️ Главное меню'
            ])
        ) {

            $lobby = Lobby::where(
                'creator_id',
                $telegramUserId
            )
            ->whereIn(
                'status',
                [
                    'waiting',
                    'playing'
                ]
            )
            ->with([
                'players.telegramUser.gameProfile',
                'creator.gameProfile'
            ])
            ->withCount('players')
            ->first();

            if (!$lobby) {

                $session->delete();

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Лобби не найдено.'
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Сохраняем новый код
            |--------------------------------------------------------------------------
            */

            $lobby->update([
                'game_room_code' => $text,
            ]);

            $session->delete();

            $newCode = $lobby->game_room_code;

            /*
            |--------------------------------------------------------------------------
            | Ссылка на игру
            |--------------------------------------------------------------------------
            */

            $gameLink =
                "https://play.suspects.io/?code={$newCode}";

            /*
            |--------------------------------------------------------------------------
            | Ссылка приглашения
            |--------------------------------------------------------------------------
            */

            $lobbyLink =
                "https://t.me/YkSUS10_bot?start=lobby_{$lobby->id}";

            /*
            |--------------------------------------------------------------------------
            | Количество игроков
            |--------------------------------------------------------------------------
            */

            $playersCount = $lobby->players_count;

            /*
            |--------------------------------------------------------------------------
            | Отправляем обновление всем игрокам
            |--------------------------------------------------------------------------
            */

            foreach ($lobby->players as $player) {

                if (!$player->telegramUser) {
                    continue;
                }

                $playerChatId =
                    $player->telegramUser->telegram_id;

                $isHost =
                    $player->telegram_user_id ==
                    $lobby->creator_id;

                /*
                |--------------------------------------------------------------------------
                | Клавиатура хоста
                |--------------------------------------------------------------------------
                */

                if ($isHost) {

                    if ($lobby->status === 'playing') {

                        $keyboard = [
                            [
                                [
                                    'text' => '👥 Игроки'
                                ]
                            ],
                            [
                                [
                                    'text' => '🏁 Завершить игру'
                                ]
                            ]
                        ];

                    } else {

                        $keyboard = [
                            [
                                [
                                    'text' => '▶️ Начать игру'
                                ]
                            ],
                            [
                                [
                                    'text' => '✏️ Изменить код'
                                ]
                            ],
                            [
                        [
                            'text' =>
                                '📤 Приглашение'
                        ]
                    ],
                            [
                                [
                                    'text' => '❌ Кикнуть игрока'
                                ]
                            ],
                            [
                                [
                                    'text' => '⬅️ Главное меню'
                                ]
                            ]
                        ];
                    }

                } else {

                    $keyboard = [
                        [
                            [
                                'text' => '👥 Игроки'
                            ]
                        ],
                        [
                            [
                                'text' => '📤 Приглашение'
                            ]
                        ],
                        [
                            [
                                'text' => '🚪 Выйти из лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '⬅️ Главное меню'
                            ]
                        ]
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Код игры
                |--------------------------------------------------------------------------
                */

                $telegram->sendMessage([
                    'chat_id' => $playerChatId,

                    'text' =>
                        "🔄 Код комнаты обновлён!\n\n" .
                        "🎮 Лобби #{$lobby->id}\n\n" .
                        "🔑 Новый код комнаты:\n\n" .
                        "{$newCode}\n\n" .
                        "🔗 Ссылка на игру:\n" .
                        "{$gameLink}",

                    'reply_markup' => json_encode([
                        'keyboard' => $keyboard,
                        'resize_keyboard' => true
                    ])
                ]);

                /*
                |--------------------------------------------------------------------------
                | Приглашение
                |--------------------------------------------------------------------------
                */
    $creatorName = 'Игрок';
        if($telegramUser->username) {
            $creatorName = '@' . $telegramUser->username;
        } elseif ($telegramUser->first_name) {
            $creatorName = $telegramUser->first_name;
        }
               $telegram->sendMessage([
    'chat_id' => $playerChatId,

    'text' =>
        "📤 Актуальное приглашение в лобби\n\n" .
        "🎮 Лобби #{$lobby->id}\n" .
        "👥 Игроки: {$playersCount}/{$lobby->max_players}\n" .
        "🟢 Ищем игроков",

    'reply_markup' => json_encode([
        'inline_keyboard' => [
            [
                [
                    'text' => '🚪 Войти в лобби',
                    'url' => $lobbyLink,
                ]
            ],
            [
                [
                    'text' => '📋 Скопировать приглашение',
                    'copy_text' => [
                        'text' =>
                            "🎮 Приглашение в лобби\n\n" .
                            "👑 Создал: {$creatorName}\n" .
                            "👥 Игроки: {$playersCount}/{$lobby->max_players}\n\n" .
                            "Присоединяйся к лобби!"
                    ]
                ]
            ]
        ]
    ])
]);
            }

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем лобби хоста
        |--------------------------------------------------------------------------
        */

        $lobby = Lobby::where(
            'creator_id',
            $telegramUserId
        )
        ->whereIn(
            'status',
            [
                'waiting',
                'playing'
            ]
        )
        ->with([
            'players.telegramUser.gameProfile'
        ])
        ->withCount('players')
        ->first();

        if (!$lobby) {
            return false;
        }


        /*
        |--------------------------------------------------------------------------
        | Начать игру
        |--------------------------------------------------------------------------
        */

        if ($text === '▶️ Начать игру') {

            if ($lobby->status === 'playing') {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '🎮 Игра уже запущена.'
                ]);

                return true;
            }

            if ($lobby->players_count < 4) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        '❌ Недостаточно игроков. Нужно минимум 4.'
                ]);

                return true;
            }

            $lobby->update([
                'status' => 'playing',
                'started_at' => now(),
            ]);

            foreach ($lobby->players as $player) {

                if (!$player->telegramUser) {
                    continue;
                }

                $isHost =
                    $player->telegram_user_id ==
                    $lobby->creator_id;

                if ($isHost) {

                    $keyboard = [
                        [
                            [
                                'text' => '👥 Игроки'
                            ]
                        ],
                        [
                            [
                                'text' => '🏁 Завершить игру'
                            ]
                        ]
                    ];

                } else {

                    $keyboard = [
                        [
                            [
                                'text' => '👥 Игроки'
                            ],
                             [
                            [
                                'text' => '📤 Приглашение'
                            ]
                        ],
                            [
                                'text' => '🚪 Выйти из лобби'
                            ]
                        ]
                    ];
                }

                $playerChatId =
                    $player->telegramUser->telegram_id;

                /*
                |--------------------------------------------------------------------------
                | Игра началась
                |--------------------------------------------------------------------------
                */

                $telegram->sendMessage([
                    'chat_id' => $playerChatId,

                    'text' =>
                        "🎮 Игра началась!\n\n" .
                        "🆔 Лобби #{$lobby->id}\n\n" .
                        "Удачной игры!\n" .
                        "👇 Код комнаты:",

                    'reply_markup' => json_encode([
                        'keyboard' => $keyboard,
                        'resize_keyboard' => true
                    ])
                ]);

                /*
                |--------------------------------------------------------------------------
                | Код комнаты
                |--------------------------------------------------------------------------
                */

                $telegram->sendMessage([
                    'chat_id' => $playerChatId,

                    'text' =>
                        $lobby->game_room_code
                ]);

                /*
                |--------------------------------------------------------------------------
                | Ссылка на игру
                |--------------------------------------------------------------------------
                */

                $telegram->sendMessage([
                    'chat_id' => $playerChatId,

                    'text' =>
                        "Нажмите ссылку и сыграйте в Suspects вместе с пользователями лобби!\n\n" .
                        "https://play.suspects.io/?code={$lobby->game_room_code}"
                ]);
            }

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Завершить игру
        |--------------------------------------------------------------------------
        */

        if ($text === '🏁 Завершить игру') {

            if ($lobby->status !== 'playing') {

                $telegram->sendMessage([
                    'chat_id' => $chatId,

                    'text' =>
                        '❌ Игра сейчас не запущена.'
                ]);

                return true;
            }

            $lobby->update([
                'status' => 'waiting',
                'started_at' => null,
            ]);

            foreach ($lobby->players as $player) {

                if (!$player->telegramUser) {
                    continue;
                }

                $isHost =
                    $player->telegram_user_id ==
                    $lobby->creator_id;

                if ($isHost) {

                    $keyboard = [
                        
                        [
                            [
                                'text' => '▶️ Начать игру'
                            ]
                        ],
                        [
                            [
                                'text' => '✏️ Изменить код'
                            ]
                        ],
                        [
                            [
                                'text' => '❌ Кикнуть игрока'
                            ]
                        ],
                        [
                            [
                                'text' => '⬅️ Главное меню'
                            ]
                        ]
                    ];

                } else {

                    $keyboard = [
                        [
                            [
                                'text' => '👥 Игроки'
                            ]
                        ],
                         [
                            [
                                'text' => '📤 Приглашение'
                            ]
                        ],
                        [
                            [
                                'text' => '🚪 Выйти из лобби'
                            ]
                        ],
                        [
                            [
                                'text' => '⬅️ Главное меню'
                            ]
                        ]
                    ];
                }

                $telegram->sendMessage([
                    'chat_id' =>
                        $player->telegramUser->telegram_id,

                    'text' =>
                        "🏁 Игра завершена.\n\n" .
                        "🎮 Лобби снова ожидает игроков.\n" .
                        "🆔 Лобби #{$lobby->id}",

                    'reply_markup' => json_encode([
                        'keyboard' => $keyboard,
                        'resize_keyboard' => true
                    ])
                ]);
            }

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Изменить код
        |--------------------------------------------------------------------------
        */

        if ($text === '✏️ Изменить код') {

            BotSession::updateOrCreate(
                [
                    'telegram_user_id' =>
                        $telegramUserId,
                ],
                [
                    'step' => 'change_lobby_code',
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    '✏️ Отправьте новый код комнаты.'
            ]);

            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Список игроков
    |--------------------------------------------------------------------------
    */

    private function playersList($lobby)
    {
        $text = '';

        foreach (
            $lobby->players as $index => $player
        ) {

            if (!$player->telegramUser) {
                continue;
            }

            $gameProfile =
                $player
                    ->telegramUser
                    ->gameProfile;

            $nickname =
                $gameProfile
                    ? $gameProfile->game_nickname
                    : 'Не привязан';

            $username =
                $player
                    ->telegramUser
                    ->username;

            if ($username) {

                $username =
                    '@' . $username;

            } else {

                $username =
                    'нет username';
            }

            $text .=
                ($index + 1) .
                ". 🎮 {$nickname}\n" .
                "   👤 {$username}\n\n";
        }

        return $text;
    }
}