<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

use App\Models\TelegramUser;
use App\Models\TelegramUserMute;
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

    /*
    |--------------------------------------------------------------------------
    | MUTE CONFIGURATION
    |--------------------------------------------------------------------------
    */

    private const MUTE_CHAT_ID = -1003793441686;
    private const MUTE_OWNER_ID = 6537808029;

    public function handle(): int
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
                        | AUTOMATIC MUTE
                        |--------------------------------------------------------------------------
                        */

                        if ($this->handleMuteMessage($message, $telegram)) {
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
                        Log::error('Update error', [
                            'message' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                            'trace' => $e->getTraceAsString(),
                        ]);

                        continue;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Telegram connection error', [
                    'message' => $e->getMessage(),
                ]);

                sleep(5);

                continue;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MUTE COMMAND HANDLER
    |--------------------------------------------------------------------------
    */

    private function handleMuteMessage($message, Api $telegram): bool
    {
        $chatId = (int) ($message->chat->id ?? 0);
        $userId = (int) ($message->from->id ?? 0);
        $messageId = (int) ($message->messageId ?? $message->message_id ?? 0);

        if ($chatId !== self::MUTE_CHAT_ID) {
            return false;
        }

        $text = mb_strtolower(trim((string) ($message->text ?? '')));

        $reply = $message->replyToMessage
            ?? $message->reply_to_message
            ?? null;

        $replyUserId = (int) (
            data_get($reply, 'from.id', 0)
        );

        $isMuteCommand = preg_match(
            '/^(?:мут\s+\d+\s+(?:минут(?:у|ы|а|ой)?|час(?:а|ов)?|д(?:ень|ня|ней))|снять\s+мут|размутить)[!.]?$/u',
            $text
        );

        /*
         * Команды принимаются только ответом на сообщение владельца.
         */
        if ($isMuteCommand && $reply && $replyUserId === self::MUTE_OWNER_ID) {
            try {
                if ($userId === 0 || $userId === self::MUTE_OWNER_ID) {
                    return true;
                }

                // Проверяем статус автора команды в этой группе.
                $member = $telegram->getChatMember([
                    'chat_id' => self::MUTE_CHAT_ID,
                    'user_id' => $userId,
                ]);

                $status = (string) data_get($member, 'status', '');

                if (!in_array($status, ['administrator', 'creator'], true)) {
                    $telegram->sendMessage([
                        'chat_id' => self::MUTE_CHAT_ID,
                        'text' => '⛔ Мут может выдавать только администратор группы.',
                        'reply_to_message_id' => $messageId,
                    ]);

                    return true;
                }

                $muteQuery = TelegramUserMute::query()
                    ->where('chat_id', self::MUTE_CHAT_ID)
                    ->where('user_id', self::MUTE_OWNER_ID);

                // Снять мут.
                if (preg_match('/^(?:снять\s+мут|размутить)[!.]?$/u', $text)) {
                    $muteQuery->delete();

                    $telegram->sendMessage([
                        'chat_id' => self::MUTE_CHAT_ID,
                        'text' => '🔊 Мут снят.',
                        'reply_to_message_id' => $messageId,
                    ]);

                    return true;
                }

                preg_match(
                    '/^мут\s+(\d+)\s+(минут(?:у|ы|а|ой)?|час(?:а|ов)?|д(?:ень|ня|ней))[!.]?$/u',
                    $text,
                    $matches
                );

                $amount = (int) ($matches[1] ?? 0);
                $unit = $matches[2] ?? '';

                if ($amount < 1 || $amount > 30) {
                    $telegram->sendMessage([
                        'chat_id' => self::MUTE_CHAT_ID,
                        'text' => 'Укажи срок от 1 до 30. Например: мут 2 часа.',
                        'reply_to_message_id' => $messageId,
                    ]);

                    return true;
                }

                if (str_starts_with($unit, 'минут')) {
                    $until = now()->addMinutes($amount);
                } elseif (str_starts_with($unit, 'час')) {
                    $until = now()->addHours($amount);
                } else {
                    $until = now()->addDays($amount);
                }

                TelegramUserMute::query()->updateOrCreate(
                    [
                        'chat_id' => self::MUTE_CHAT_ID,
                        'user_id' => self::MUTE_OWNER_ID,
                    ],
                    [
                        'muted_by' => $userId,
                        'muted_until' => $until,
                    ]
                );

                $telegram->sendMessage([
                    'chat_id' => self::MUTE_CHAT_ID,
                    'text' => '🔇 Мут выдан до ' . $until->format('d.m.Y H:i') . '.',
                    'reply_to_message_id' => $messageId,
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::error('Telegram mute command failed', [
                    'chat_id' => $chatId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);

                try {
                    $telegram->sendMessage([
                        'chat_id' => self::MUTE_CHAT_ID,
                        'text' => '❌ Ошибка обработки мута. Проверь логи Laravel.',
                    ]);
                } catch (\Throwable $ignored) {
                    // Не прерываем основной цикл бота.
                }

                return true;
            }
        }

        /*
         * Удаляем новые сообщения владельца, пока мут действует.
         */
        if ($userId !== self::MUTE_OWNER_ID) {
            return false;
        }

        $mute = TelegramUserMute::query()
            ->where('chat_id', self::MUTE_CHAT_ID)
            ->where('user_id', self::MUTE_OWNER_ID)
            ->where('muted_until', '>', now())
            ->first();

        if (!$mute) {
            return false;
        }

        try {
            if ($messageId > 0) {
                $telegram->deleteMessage([
                    'chat_id' => self::MUTE_CHAT_ID,
                    'message_id' => $messageId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not delete muted owner message', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }

        // Не передаём сообщения владельца другим обработчикам.
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | MAIN MENU
    |--------------------------------------------------------------------------
    */

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
