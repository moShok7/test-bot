
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
use App\Services\Bot\TopicAccessHandler;
use App\Services\Bot\UserInfoHandler;

use App\Services\Bot\Lobby\LobbyHandler;
use App\Services\Bot\Lobby\KickPlayerHandler;
use App\Services\Bot\Lobby\LobbyService;
use App\Services\Bot\Lobby\CreateLobbyHandler;
use App\Services\Bot\Lobby\SearchLobbyHandler;
use App\Services\Bot\Lobby\JoinLobbyHandler;

use App\Services\Bot\Clan\ClanHandler;
use App\Services\Bot\Clan\ClanModerationService;

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

        $userInfoHandler = new UserInfoHandler();

        $topicAccessHandler = new TopicAccessHandler();

        $adminHandler = new AdminHandler();

        /*
        |--------------------------------------------------------------------------
        | Clan handlers
        |--------------------------------------------------------------------------
        */

        $clanHandler = new ClanHandler();

        $clanModerationService = new ClanModerationService();

        $this->info('Bot started');

        $offset = 0;

        /*
        |--------------------------------------------------------------------------
        | Основной цикл
        |--------------------------------------------------------------------------
        */

        while (true) {
            try {
                $lobbyService->deleteExpiredWaitingLobbies();

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

                            $callbackData = $callback->data ?? '';

                            if (str_starts_with($callbackData, 'clan_')) {
                                if ($clanHandler->handleCallback($callback, $telegram)) {
                                    continue;
                                }
                            }

                            if (str_starts_with($callbackData, 'kick_player_')) {
                                $kickPlayerHandler->handle(
                                    (object) [
                                        'callback_query' => $callback,
                                    ],
                                    $telegram
                                );

                                continue;
                            }

                            if (str_starts_with($callbackData, 'join_lobby_')) {
                                $message = $callback->message;

                                $message['from'] = $callback->from;

                                $message['text'] = '🚪 Войти #' .
                                    str_replace('join_lobby_', '', $callbackData);

                                $joinLobbyHandler->handle($message, $telegram);

                                try {
                                    $telegram->answerCallbackQuery([
                                        'callback_query_id' => $callback->id,
                                    ]);
                                } catch (\Throwable $e) {
                                    // Callback may already have been answered.
                                }

                                continue;
                            }

                            $adminHandler->handleCallback($callback, $telegram);

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

                        $text = trim((string) ($message->text ?? ''));

                        $telegramId = $message->from->id ?? null;

                        /*
                        |--------------------------------------------------------------------------
                        | Доступ к темам Telegram
                        |--------------------------------------------------------------------------
                        */

                        if ($topicAccessHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Автоматическая авторизация
                        |--------------------------------------------------------------------------
                        */

                        $user = $message->from ?? null;

                        if ($user && $user->id) {
                            TelegramUser::updateOrCreate(
                                [
                                    'telegram_id' => $user->id,
                                ],
                                [
                                    'username' => $user->username ?? null,
                                    'first_name' => $user->first_name ?? 'Пользователь',
                                    'last_name' => $user->last_name ?? null,
                                ]
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ПЕРЕХВАТ ВВОДА ИГРОВОГО ПРОФИЛЯ
                        |--------------------------------------------------------------------------
                        |
                        | Пока пользователь вводит ник, ID или скриншот,
                        | сообщение обрабатывается здесь и не доходит
                        | до глобального чата.
                        |--------------------------------------------------------------------------
                        */

                        if ($telegramId) {
                            $profileGuardUser = TelegramUser::where(
                                'telegram_id',
                                $telegramId
                            )->first();

                            if ($profileGuardUser) {
                                $profileGuardSteps = [
                                    'nickname',
                                    'game_id',
                                    'screenshot',
                                    'edit_nickname',
                                    'edit_game_id',
                                    'edit_screenshot',
                                ];

                                $profileGuardSession = BotSession::where(
                                    'telegram_user_id',
                                    $profileGuardUser->id
                                )
                                    ->whereIn('step', $profileGuardSteps)
                                    ->first();

                                if ($profileGuardSession) {
                                    $gameProfileHandler->handle(
                                        $message,
                                        $telegram
                                    );

                                    continue;
                                }
                            }
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Сброс старых BotSession
                        |--------------------------------------------------------------------------
                        */

                        $resetSessionButtons = [
                            '❌ Удалить лобби',
                            '⬅️ Главное меню',
                        ];

                        if (
                            $telegramId &&
                            in_array($text, $resetSessionButtons, true)
                        ) {
                            $telegramUser = TelegramUser::where(
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

                        if ($settingsHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Защита от попадания редактирования профиля в глобальный чат
                        |--------------------------------------------------------------------------
                        */

                        $profileGuardUser = $telegramId
                            ? TelegramUser::where(
                                'telegram_id',
                                $telegramId
                            )->first()
                            : null;

                        if (
                            $profileGuardUser &&
                            !empty($profileGuardUser->profile_editing)
                        ) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | DEEP LINK ЛОББИ
                        |--------------------------------------------------------------------------
                        */

                        if (str_starts_with($text, '/start lobby_')) {
                            $joinLobbyHandler->handleDeepLink($message, $telegram);

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | DEEP LINK КЛАНА
                        |--------------------------------------------------------------------------
                        */

                        if (str_starts_with($text, '/start clan_')) {
                            $clanHandler->handleDeepLink($message, $telegram);

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Модерация клана
                        |--------------------------------------------------------------------------
                        */

                        if ($clanModerationService->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Система кланов
                        |--------------------------------------------------------------------------
                        */

                        if ($clanHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | /bolt
                        |--------------------------------------------------------------------------
                        */

                        if ($text === '/bolt') {
                            app(\App\Services\Bot\BoltHandler::class)->handle(
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
                            if ($telegramId) {
                                $telegramUser = TelegramUser::where(
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
                                'chat_id' => $message->chat->id,
                                'text' => "🎮 Добро пожаловать в YkSUS!\n\nВыберите действие:",
                                'reply_markup' => json_encode([
                                    'keyboard' => [
                                        [
                                            ['text' => '➕ Создать лобби'],
                                            ['text' => '🔍 Найти лобби'],
                                        ],
                                        [
                                            ['text' => '🎮 Моё лобби'],
                                        ],
                                        [
                                            ['text' => '⚙️ Настройки'],
                                        ],
                                    ],
                                    'resize_keyboard' => true,
                                ]),
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
                                'chat_id' => $message->chat->id,
                                'text' => '🎮 Игровое меню',
                                'reply_markup' => json_encode([
                                    'keyboard' => [
                                        [
                                            ['text' => '➕ Создать лобби'],
                                            ['text' => '🔍 Найти лобби'],
                                        ],
                                        [
                                            ['text' => '🎮 Моё лобби'],
                                        ],
                                        [
                                            ['text' => '⚙️ Настройки'],
                                        ],
                                    ],
                                    'resize_keyboard' => true,
                                ]),
                            ]);

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | СОЗДАНИЕ ЛОББИ
                        |--------------------------------------------------------------------------
                        */

                        if ($createLobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ПОИСК ЛОББИ
                        |--------------------------------------------------------------------------
                        */

                        if ($searchLobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ВХОД В ЛОББИ / ПРИГЛАШЕНИЯ
                        |--------------------------------------------------------------------------
                        */

                        if ($joinLobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Админ
                        |--------------------------------------------------------------------------
                        */

                        if ($adminHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Игровой профиль
                        |--------------------------------------------------------------------------
                        */

                        if ($gameProfileHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Остальные функции лобби
                        |--------------------------------------------------------------------------
                        */

                        if ($lobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Информация о пользователе
                        |--------------------------------------------------------------------------
                        |
                        | Команды профиля не должны попадать в глобальный чат.
                        |
                        */

                        if ($userInfoHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Глобальный чат
                        |--------------------------------------------------------------------------
                        */

                        $globalChatHandler->handle($message, $telegram);

                    } catch (\Throwable $e) {
                        \Log::error('Update error', [
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        continue;
                    }
                }
            } catch (\Throwable $e) {
                \Log::error('Telegram connection error', [
                    'message' => $e->getMessage(),
                ]);

                sleep(5);

                continue;
            }
        }
    }
}
