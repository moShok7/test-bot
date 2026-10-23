<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api;

use App\Models\TelegramUser;
use App\Models\BotSession;

use App\Services\Bot\SettingsHandler;
use App\Services\Bot\AdminHandler;
use App\Services\Bot\GlobalChatHandler;
use App\Services\Bot\TopicAccessHandler;
use App\Services\Bot\UserInfoHandler;

use App\Services\Bot\Lobby\LobbyHandler;
use App\Services\Bot\Lobby\KickPlayerHandler;
use App\Services\Bot\Lobby\LobbyService;
use App\Services\Bot\Lobby\CreateLobbyHandler;
use App\Services\Bot\Lobby\PremierLobbyHandler;
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
        $telegram = new Api(env('TELEGRAM_BOT_TOKEN'));

        /*
        |--------------------------------------------------------------------------
        | Handlers
        |--------------------------------------------------------------------------
        */

        $lobbyService = new LobbyService($telegram);

        $settingsHandler = new SettingsHandler();
        $lobbyHandler = new LobbyHandler($lobbyService);
        $createLobbyHandler = new CreateLobbyHandler();
        $premierLobbyHandler = new PremierLobbyHandler();
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
                                    // Callback мог быть уже обработан.
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
                        $chatId = $message->chat->id;

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
                                ['telegram_id' => $user->id],
                                [
                                    'username' => $user->username ?? null,
                                    'first_name' => $user->first_name ?? 'Пользователь',
                                ]
                            );
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Сброс сессии
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
                        | Настройки и профиль
                        |--------------------------------------------------------------------------
                        */

                        if ($settingsHandler->handle($message, $telegram)) {
                            continue;
                        }

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
                        | Кланы
                        |--------------------------------------------------------------------------
                        */

                        if ($clanModerationService->handle($message, $telegram)) {
                            continue;
                        }

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

                            $this->sendMainMenu(
                                $telegram,
                                $chatId,
                                "🎮 Добро пожаловать в YkSUS!\n\nВыберите действие:"
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Главное / игровое меню
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $text === '/game' ||
                            $text === '🎮 Игровое меню' ||
                            $text === '⬅️ Главное меню'
                        ) {
                            $this->sendMainMenu(
                                $telegram,
                                $chatId,
                                '🎮 Игровое меню'
                            );

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | СОЗДАНИЕ ОБЫЧНОГО И ПРЕМЬЕРНОГО ЛОББИ
                        |--------------------------------------------------------------------------
                        |
                        | Сначала обычный обработчик.
                        | Если он не обработал премьерную кнопку, передаём
                        | сообщение обработчику премьерных лобби.
                        |--------------------------------------------------------------------------
                        */
                        if ($premierLobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

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
                        | Администратор
                        |--------------------------------------------------------------------------
                        */

                        if ($adminHandler->handle($message, $telegram)) {
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

    /*
    |--------------------------------------------------------------------------
    | Главное меню
    |--------------------------------------------------------------------------
    */

    private function sendMainMenu(Api $telegram, $chatId, string $title): void
    {
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $title,
            'reply_markup' => json_encode([
                'keyboard' => [
                    [
                        ['text' => '➕ Создать лобби'],
                        ['text' => '🔍 Найти лобби'],
                    ],
                    [
                        ['text' => '🎮 Моё лобби'],
                        ['text' => '👤 Профиль'],
                    ],
                    [
                        ['text' => '⚙️ Настройки'],
                    ],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
