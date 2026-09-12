<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;

use App\Models\TelegramUser;
use App\Models\BotSession;

use App\Services\Bot\SettingsHandler;
use App\Services\Bot\GameProfileHandler;
use App\Services\Bot\AdminHandler;
use App\Services\Bot\GlobalChatHandler;

use App\Services\Bot\Lobby\LobbyHandler;
use App\Services\Bot\Lobby\KickPlayerHandler;
use App\Services\Bot\Lobby\LobbyService;
use App\Services\Bot\Lobby\CreateLobbyHandler;
use App\Services\Bot\Lobby\SearchLobbyHandler;
use App\Services\Bot\Lobby\JoinLobbyHandler;

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

        $createLobbyHandler = new CreateLobbyHandler();

        $searchLobbyHandler = new SearchLobbyHandler();

        $joinLobbyHandler = new JoinLobbyHandler();

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

                        $offset =
                            $update->updateId + 1;

                        /*
                        |--------------------------------------------------------------------------
                        | CALLBACK BUTTONS
                        |--------------------------------------------------------------------------
                        */

                        if ($update->callbackQuery) {

                            $callback =
                                $update->callbackQuery;

                            $callbackData =
                                $callback->data ?? '';

                            /*
                            |--------------------------------------------------------------------------
                            | Кик игрока
                            |--------------------------------------------------------------------------
                            */

                            if (
                                str_starts_with(
                                    $callbackData,
                                    'kick_player_'
                                )
                            ) {

                                $kickPlayerHandler->handle(
                                    (object) [
                                        'callback_query' =>
                                            $callback
                                    ],
                                    $telegram
                                );

                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Вход в лобби
                            |--------------------------------------------------------------------------
                            */

                            if (
                                str_starts_with(
                                    $callbackData,
                                    'join_lobby_'
                                )
                            ) {

                                $message =
                                    $callback->message;

                                $message['from'] =
                                    $callback->from;

                                $message['text'] =
                                    '🚪 Войти #' .
                                    str_replace(
                                        'join_lobby_',
                                        '',
                                        $callbackData
                                    );

                                $joinLobbyHandler->handle(
                                    $message,
                                    $telegram
                                );

                                try {

                                    $telegram->answerCallbackQuery([
                                        'callback_query_id' =>
                                            $callback->id
                                    ]);

                                } catch (\Throwable $e) {
                                }

                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Остальные callback
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

                        $message =
                            $update->message;

                        if (!$message) {
                            continue;
                        }

                        $text =
                            trim(
                                $message->text ?? ''
                            );

                        $telegramId =
                            $message->from->id ?? null;

                        /*
                        |--------------------------------------------------------------------------
                        | Автоматическая авторизация
                        |--------------------------------------------------------------------------
                        */

                        $user =
                            $message->from;

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

                                    'last_name' =>
                                        $user->last_name ?? null,
                                ]
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ВАЖНО:
                        | Команды навигации сбрасывают старую BotSession
                        |--------------------------------------------------------------------------
                        |
                        | Это решает проблему:
                        |
                        | Создал лобби
                        | ↓
                        | удалил чат
                        | ↓
                        | снова открыл бота
                        | ↓
                        | нажал другую кнопку
                        |
                        | Старая lobby_code сессия больше не мешает.
                        |--------------------------------------------------------------------------
                        */

                        $resetSessionButtons = [
                            '⬅️ Главное меню',
                            '🎮 Игровое меню',
                            '🔍 Найти лобби',
                            '🔄 Обновить поиск',
                            '➕ Создать лобби',
                            '🎮 Моё лобби',
                            '⚙️ Настройки',
                            '🚪 Выйти из лобби',
                            '📤 Приглашение',
                            '▶️ Начать игру',
                            '✏️ Изменить код',
                            '👥 Игроки',
                            '❌ Кикнуть игрока',
                        ];

                        if (
                            $telegramId &&
                            in_array(
                                $text,
                                $resetSessionButtons,
                                true
                            )
                        ) {

                            /*
                            |--------------------------------------------------------------------------
                            | ВАЖНО:
                            | Используем ID TelegramUser, а не Telegram ID.
                            |--------------------------------------------------------------------------
                            */

                            $telegramUser =
                                TelegramUser::where(
                                    'telegram_id',
                                    $telegramId
                                )->first();

                            if ($telegramUser) {

                                BotSession::where(
                                    'telegram_user_id',
                                    $telegramUser->id
                                )->delete();
                            }
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Настройки
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

                            $joinLobbyHandler->handleDeepLink(
                                $message,
                                $telegram
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | /bolt
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

                            /*
                            | Сбрасываем старую сессию при новом /start
                            */

                            if ($telegramId) {

                                $telegramUser =
                                    TelegramUser::where(
                                        'telegram_id',
                                        $telegramId
                                    )->first();

                                if ($telegramUser) {

                                    BotSession::where(
                                        'telegram_user_id',
                                        $telegramUser->id
                                    )->delete();
                                }
                            }

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
                        | СОЗДАНИЕ ЛОББИ
                        |--------------------------------------------------------------------------
                        |
                        | ВАЖНО:
                        | Проверяем раньше общего LobbyHandler.
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $createLobbyHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ПОИСК ЛОББИ
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $searchLobbyHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ВХОД В ЛОББИ / ПРИГЛАШЕНИЯ
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $joinLobbyHandler->handle(
                                $message,
                                $telegram
                            )
                        ) {
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
                        | Остальные функции лобби
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

                                'trace' =>
                                    $e->getTraceAsString(),
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
