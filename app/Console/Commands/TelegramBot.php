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
use App\Services\Bot\Lobby\PremierLobbyApplicationHandler;
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

        $lobbyService = new LobbyService($telegram);

        $settingsHandler = new SettingsHandler();
        $lobbyHandler = new LobbyHandler($lobbyService);
        $createLobbyHandler = new CreateLobbyHandler();
        $premierLobbyHandler = new PremierLobbyHandler();
        $premierApplicationHandler = new PremierLobbyApplicationHandler();
        $searchLobbyHandler = new SearchLobbyHandler();
        $joinLobbyHandler = new JoinLobbyHandler();
        $kickPlayerHandler = new KickPlayerHandler();
        $globalChatHandler = new GlobalChatHandler();
        $userInfoHandler = new UserInfoHandler();
        $topicAccessHandler = new TopicAccessHandler();
        $adminHandler = new AdminHandler();

        $clanHandler = new ClanHandler();
        $clanModerationService = new ClanModerationService();

        $this->info('Bot started');

        $offset = 0;

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
                            $callbackData = (string) ($callback->data ?? '');

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

                            /*
                            |--------------------------------------------------------------------------
                            | PREMIER LOBBY APPLICATIONS
                            |--------------------------------------------------------------------------
                            */

                            if (
                                $premierApplicationHandler->handleCallback(
                                    $callback,
                                    $telegram
                                )
                            ) {
                                continue;
                            }

                            $adminHandler->handleCallback($callback, $telegram);

                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ORDINARY MESSAGE
                        |--------------------------------------------------------------------------
                        */

                        $message = $update->message;

                        if (!$message) {
                            continue;
                        }

                        $text = trim((string) ($message->text ?? ''));
                        $telegramId = $message->from->id ?? null;
                        $chatId = $message->chat->id ?? null;

                        if (!$chatId) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | TELEGRAM TOPIC ACCESS
                        |--------------------------------------------------------------------------
                        */

                        if ($topicAccessHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | AUTOMATIC AUTHORIZATION
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
                        | RESET SESSION
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
                        | SETTINGS
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
                        | LOBBY DEEP LINK
                        |--------------------------------------------------------------------------
                        */

                        if (str_starts_with($text, '/start lobby_')) {
                            $joinLobbyHandler->handleDeepLink($message, $telegram);
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | CLAN DEEP LINK
                        |--------------------------------------------------------------------------
                        */

                        if (str_starts_with($text, '/start clan_')) {
                            $clanHandler->handleDeepLink($message, $telegram);
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | CLANS
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
                        | MAIN / GAME MENU
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
                        | CREATE REGULAR OR PREMIER LOBBY
                        |--------------------------------------------------------------------------
                        |
                        | Premier handler must run first so it can continue
                        | an existing premier creation session.
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
                        | SEARCH LOBBIES
                        |--------------------------------------------------------------------------
                        */

                        if ($searchLobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | JOIN LOBBY / INVITATIONS
                        |--------------------------------------------------------------------------
                        */

                        if ($joinLobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | ADMIN
                        |--------------------------------------------------------------------------
                        */

                        if ($adminHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | OTHER LOBBY FEATURES
                        |--------------------------------------------------------------------------
                        */

                        if ($lobbyHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | USER INFORMATION
                        |--------------------------------------------------------------------------
                        */

                        if ($userInfoHandler->handle($message, $telegram)) {
                            continue;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | GLOBAL CHAT
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

    private function sendMainMenu(
        Api $telegram,
        $chatId,
        string $title
    ): void {
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
