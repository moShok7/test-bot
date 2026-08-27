<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;

use App\Models\TelegramUser;
use App\Services\Bot\SettingsHandler;
use App\Services\Bot\GameProfileHandler;
use App\Services\Bot\AdminHandler;
use App\Services\Bot\GlobalChatHandler;
use App\Services\Bot\Lobby\LobbyHandler;
use App\Services\Bot\Lobby\KickPlayerHandler;
use App\Services\Bot\Lobby\LobbyService;

class TelegramBot extends Command
{
    protected $signature = 'telegram:bot';

    protected $description = 'Telegram bot';

    public function handle()
    {
        $telegram = new Api(
            env('TELEGRAM_BOT_TOKEN')
        );

        /*
        |--------------------------------------------------------------------------
        | Handlers
        |--------------------------------------------------------------------------
        */

        $gameProfileHandler = new GameProfileHandler();

        $lobbyService = new LobbyService($telegram);

        $settingsHandler = new SettingsHandler();

        $lobbyHandler = new LobbyHandler($lobbyService);

        $kickPlayerHandler = new KickPlayerHandler();

        $globalChatHandler = new GlobalChatHandler();

        $adminHandler = new AdminHandler();

        $this->info('Bot started');

        $offset = 0;

        /*
        |--------------------------------------------------------------------------
        | Основной цикл
        |--------------------------------------------------------------------------
        */

        while (true) {

            try {

                /*
                |--------------------------------------------------------------------------
                | Удаляем старые лобби
                |--------------------------------------------------------------------------
                */

                $lobbyService->deleteExpiredWaitingLobbies();

                /*
                |--------------------------------------------------------------------------
                | Получаем Telegram updates
                |--------------------------------------------------------------------------
                */

                $updates = $telegram->getUpdates([
                    'offset' => $offset,
                    'timeout' => 30,
                ]);

                foreach ($updates as $update) {

                    try {

                        $offset = $update->updateId + 1;

                        /*
                        |--------------------------------------------------------------------------
                        | CALLBACK BUTTONS
                        |--------------------------------------------------------------------------
                        */

                        if ($update->callbackQuery) {

                            $callback = $update->callbackQuery;

                            $callbackData =
                                $callback->data ?? '';

                            /*
                            |--------------------------------------------------------------------------
                            | ❌ Кик игрока
                            |--------------------------------------------------------------------------
                            */

                            if (
                                str_starts_with(
                                    $callbackData,
                                    'kick_player_'
                                )
                            ) {

                                \Log::info('KICK CALLBACK RECEIVED', [
                                    'data' => $callbackData,
                                    'callback_id' => $callback->id ?? null,
                                    'from_id' => $callback->from->id ?? null,
                                ]);

                                $kickPlayerHandler->handle(
                                    (object) [
                                        'callback_query' => $callback
                                    ],
                                    $telegram
                                );

                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | 🚪 Вход в лобби через inline кнопку
                            |--------------------------------------------------------------------------
                            */

                            if (
                                str_starts_with(
                                    $callbackData,
                                    'join_lobby_'
                                )
                            ) {

                                $lobbyId = str_replace(
                                    'join_lobby_',
                                    '',
                                    $callbackData
                                );

                                $message =
                                    $callback->message;

                                /*
                                |--------------------------------------------------------------------------
                                | Передаём информацию о пользователе
                                |--------------------------------------------------------------------------
                                */

                                $message['from'] =
                                    $callback->from;

                                /*
                                |--------------------------------------------------------------------------
                                | Имитируем обычную кнопку
                                |--------------------------------------------------------------------------
                                */

                                $message['text'] =
                                    '🚪 Войти #' . $lobbyId;

                                $lobbyHandler->handle(
                                    $message,
                                    $telegram
                                );

                                /*
                                |--------------------------------------------------------------------------
                                | Убираем часики с inline-кнопки
                                |--------------------------------------------------------------------------
                                */

                                try {

                                    $telegram->answerCallbackQuery([
                                        'callback_query_id' =>
                                            $callback->id
                                    ]);

                                } catch (\Throwable $e) {

                                    \Log::warning(
                                        'Join callback answer error',
                                        [
                                            'message' =>
                                                $e->getMessage()
                                        ]
                                    );
                                }

                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Админские callback-кнопки
                            |--------------------------------------------------------------------------
                            */

                            $adminHandler->handleCallback(
                                $callback,
                                $telegram
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Обычное сообщение
                        |--------------------------------------------------------------------------
                        */

                        $message = $update->message;

                        if (!$message) {
                            continue;
                        }

                        $text = trim(
                            $message->text ?? ''
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | Автоматическая авторизация
                        |--------------------------------------------------------------------------
                        */

                        $user = $message->from;

                        if (
                            $user &&
                            $user->id
                        ) {

                            TelegramUser::updateOrCreate(
                                [
                                    'telegram_id' =>
                                        $user->id,
                                ],
                                [
                                    'username' =>
                                        $user->username,

                                    'first_name' =>
                                        $user->first_name,
                                ]
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ⚙️ Настройки
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $settingsHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Deep Link лобби
                        |--------------------------------------------------------------------------
                        */

                        if (
                            str_starts_with(
                                $text,
                                '/start lobby_'
                            )
                        ) {

                            app(
                                \App\Services\Bot\Lobby\JoinLobbyHandler::class
                            )->handleDeepLink(
                                $message,
                                $telegram
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Bolt
                        |--------------------------------------------------------------------------
                        */

                        if ($text === '/bolt') {

                            app(
                                \App\Services\Bot\BoltHandler::class
                            )->handle(
                                $message,
                                $telegram
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | /start
                        |--------------------------------------------------------------------------
                        */

                        if ($text === '/start') {

                            $telegram->sendMessage([
                                'chat_id' =>
                                    $message->chat->id,

                                'text' =>
                                    "🎮 Добро пожаловать в YkSUS!\n\n" .
                                    "Выберите действие:",

                                'reply_markup' =>
                                    json_encode([
                                        'keyboard' => [

                                            [
                                                [
                                                    'text' =>
                                                        '➕ Создать лобби'
                                                ],
                                                [
                                                    'text' =>
                                                        '🔍 Найти лобби'
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
                                                        '⚙️ Настройки'
                                                ]
                                            ]

                                        ],

                                        'resize_keyboard' =>
                                            true
                                    ])
                            ]);

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Игровое меню
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $text === '/game' ||
                            $text === '🎮 Игровое меню'
                        ) {

                            $telegram->sendMessage([
                                'chat_id' =>
                                    $message->chat->id,

                                'text' =>
                                    "🎮 Игровое меню",

                                'reply_markup' =>
                                    json_encode([
                                        'keyboard' => [

                                            [
                                                [
                                                    'text' =>
                                                        '➕ Создать лобби'
                                                ],
                                                [
                                                    'text' =>
                                                        '🔍 Найти лобби'
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
                                                        '⚙️ Настройки'
                                                ]
                                            ]

                                        ],

                                        'resize_keyboard' =>
                                            true
                                    ])
                            ]);

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Админ
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $adminHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Игровой профиль
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $gameProfileHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Лобби
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $lobbyHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Глобальный чат
                        |--------------------------------------------------------------------------
                        */

                        $globalChatHandler->handle(
                            $message,
                            $telegram
                        );

                    } catch (\Throwable $e) {

                        \Log::error(
                            'Update error',
                            [
                                'message' =>
                                    $e->getMessage(),

                                'file' =>
                                    $e->getFile(),

                                'line' =>
                                    $e->getLine(),
                            ]
                        );

                        continue;
                    }
                }

            } catch (\Throwable $e) {

                \Log::error(
                    'Telegram connection error',
                    [
                        'message' =>
                            $e->getMessage(),
                    ]
                );

                sleep(5);

                continue;
            }
        }
    }
}

