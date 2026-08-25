<?php

namespace App\Services\Bot\Lobby;

use App\Models\TelegramUser;
use App\Models\Lobby;
use App\Models\LobbyPlayer;

class JoinLobbyHandler
{
    /*
    |--------------------------------------------------------------------------
    | Основной обработчик сообщений
    |--------------------------------------------------------------------------
    */

    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');
        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $chatId = $message->chat->id ?? $telegramId;

        /*
        |--------------------------------------------------------------------------
        | Кнопка "📤 Приглашение"
        |--------------------------------------------------------------------------
        */

        if ($text === '📤 Приглашение') {

            $telegramUser = TelegramUser::where(
                'telegram_id',
                $telegramId
            )->first();

            if (!$telegramUser) {
                return false;
            }

            $lobby = Lobby::whereHas(
                'players',
                function ($query) use ($telegramUser) {
                    $query->where(
                        'telegram_user_id',
                        $telegramUser->id
                    );
                }
            )
            ->whereIn(
                'status',
                ['waiting', 'playing']
            )
            ->with([
                'players.telegramUser',
                'creator'
            ])
            ->withCount('players')
            ->first();

            if (!$lobby) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Вы сейчас не находитесь в лобби."
                ]);

                $this->sendMainMenu(
                    $chatId,
                    $telegram
                );

                return true;
            }

            $this->sendLobbyInvite(
                $chatId,
                $lobby,
                $telegram
            );

            return true;
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
        | Кнопка "🚪 Войти #..."
        |--------------------------------------------------------------------------
        */

        if (!str_starts_with($text, '🚪 Войти #')) {
            return false;
        }

        $lobbyId = str_replace(
            '🚪 Войти #',
            '',
            $text
        );

        if (!is_numeric($lobbyId)) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем лобби
        |--------------------------------------------------------------------------
        */

        $lobby = Lobby::where(
            'id',
            $lobbyId
        )
        ->where(
            'status',
            'waiting'
        )
        ->with([
            'players.telegramUser',
            'creator'
        ])
        ->withCount('players')
        ->first();

        if (!$lobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Лобби недоступно."
            ]);

            $this->sendMainMenu(
                $chatId,
                $telegram
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем заполненность
        |--------------------------------------------------------------------------
        */

        if (
            $lobby->players_count >=
            $lobby->max_players
        ) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Лобби заполнено."
            ]);

            $this->sendMainMenu(
                $chatId,
                $telegram
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже в этом лобби
        |--------------------------------------------------------------------------
        */

        $already = LobbyPlayer::where(
            'lobby_id',
            $lobby->id
        )
        ->where(
            'telegram_user_id',
            $telegramUser->id
        )
        ->exists();

        if ($already) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "⚠️ Вы уже в этом лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже в другом лобби
        |--------------------------------------------------------------------------
        */

        $inAnotherLobby = LobbyPlayer::where(
            'telegram_user_id',
            $telegramUser->id
        )
        ->whereHas(
            'lobby',
            function ($query) {
                $query->whereIn(
                    'status',
                    ['waiting', 'playing']
                );
            }
        )
        ->exists();

        if ($inAnotherLobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "⚠️ Вы уже находитесь в другом лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Добавляем игрока
        |--------------------------------------------------------------------------
        */

        LobbyPlayer::create([
            'lobby_id' => $lobby->id,
            'telegram_user_id' => $telegramUser->id,
            'ready' => false
        ]);

        /*
        |--------------------------------------------------------------------------
        | Обновляем лобби
        |--------------------------------------------------------------------------
        */

        $lobby->refresh();

        $lobby->load([
            'players.telegramUser',
            'creator'
        ]);

        $lobby->loadCount('players');

        /*
        |--------------------------------------------------------------------------
        | Уведомляем игроков
        |--------------------------------------------------------------------------
        */

        $this->notifyPlayers(
            $lobby,
            $telegram,
            $telegramUser
        );

        /*
        |--------------------------------------------------------------------------
        | Информация о лобби
        |--------------------------------------------------------------------------
        */

        $this->sendLobbyInfo(
            $chatId,
            $lobby,
            $telegram
        );

        /*
        |--------------------------------------------------------------------------
        | Код игры
        |--------------------------------------------------------------------------
        */

        $this->sendGameAccess(
            $chatId,
            $lobby,
            $telegram
        );

        /*
        |--------------------------------------------------------------------------
        | Приглашение
        |--------------------------------------------------------------------------
        */

        $this->sendLobbyInvite(
            $chatId,
            $lobby,
            $telegram
        );

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Callback "join_lobby_ID"
    |--------------------------------------------------------------------------
    */

    public function handleCallback(
        $callback,
        $telegram
    ): bool {

        $data = $callback->data ?? '';

        if (
            !str_starts_with(
                $data,
                'join_lobby_'
            )
        ) {
            return false;
        }

        $lobbyId = str_replace(
            'join_lobby_',
            '',
            $data
        );

        $chatId =
            $callback->message->chat->id ?? null;

        $telegramId =
            $callback->from->id ?? null;

        if (!$telegramId || !$chatId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Убираем часики с inline-кнопки
        |--------------------------------------------------------------------------
        */

        try {
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback->id
            ]);
        } catch (\Throwable $e) {
        }

        /*
        |--------------------------------------------------------------------------
        | TelegramUser
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
        | Получаем лобби
        |--------------------------------------------------------------------------
        */

        $lobby = Lobby::where(
            'id',
            $lobbyId
        )
        ->where(
            'status',
            'waiting'
        )
        ->with([
            'players.telegramUser',
            'creator'
        ])
        ->withCount('players')
        ->first();

        if (!$lobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Лобби недоступно."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем заполненность
        |--------------------------------------------------------------------------
        */

        if (
            $lobby->players_count >=
            $lobby->max_players
        ) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Лобби заполнено."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже в этом лобби
        |--------------------------------------------------------------------------
        */

        $already = LobbyPlayer::where(
            'lobby_id',
            $lobby->id
        )
        ->where(
            'telegram_user_id',
            $telegramUser->id
        )
        ->exists();

        if ($already) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "⚠️ Вы уже в этом лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже в другом лобби
        |--------------------------------------------------------------------------
        */

        $inAnotherLobby = LobbyPlayer::where(
            'telegram_user_id',
            $telegramUser->id
        )
        ->whereHas(
            'lobby',
            function ($query) {
                $query->whereIn(
                    'status',
                    ['waiting', 'playing']
                );
            }
        )
        ->exists();

        if ($inAnotherLobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "⚠️ Вы уже находитесь в другом лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Добавляем игрока
        |--------------------------------------------------------------------------
        */

        LobbyPlayer::create([
            'lobby_id' => $lobby->id,
            'telegram_user_id' => $telegramUser->id,
            'ready' => false
        ]);

        /*
        |--------------------------------------------------------------------------
        | Обновляем данные
        |--------------------------------------------------------------------------
        */

        $lobby->refresh();

        $lobby->load([
            'players.telegramUser',
            'creator'
        ]);

        $lobby->loadCount('players');

        /*
        |--------------------------------------------------------------------------
        | Уведомления
        |--------------------------------------------------------------------------
        */

        $this->notifyPlayers(
            $lobby,
            $telegram,
            $telegramUser
        );

        $this->sendLobbyInfo(
            $chatId,
            $lobby,
            $telegram
        );

        $this->sendGameAccess(
            $chatId,
            $lobby,
            $telegram
        );

        $this->sendLobbyInvite(
            $chatId,
            $lobby,
            $telegram
        );

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Deep Link
    |--------------------------------------------------------------------------
    */

    public function handleDeepLink(
        $message,
        $telegram
    ): bool {

        $text =
            trim($message->text ?? '');

        $telegramId =
            $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        if (
            !str_starts_with(
                $text,
                '/start lobby_'
            )
        ) {
            return false;
        }

        $lobbyId =
            str_replace(
                '/start lobby_',
                '',
                $text
            );

        if (!is_numeric($lobbyId)) {
            return false;
        }

        $chatId =
            $message->chat->id ?? $telegramId;

        /*
        |--------------------------------------------------------------------------
        | TelegramUser
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
        | Получаем лобби
        |--------------------------------------------------------------------------
        */

        $lobby = Lobby::where(
            'id',
            $lobbyId
        )
        ->where(
            'status',
            'waiting'
        )
        ->with([
            'players.telegramUser',
            'creator'
        ])
        ->withCount('players')
        ->first();

        if (!$lobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Лобби уже запущено или недоступно."
            ]);

            $this->sendMainMenu(
                $chatId,
                $telegram
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Заполненность
        |--------------------------------------------------------------------------
        */

        if (
            $lobby->players_count >=
            $lobby->max_players
        ) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Лобби заполнено."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже в этом лобби
        |--------------------------------------------------------------------------
        */

        $already = LobbyPlayer::where(
            'lobby_id',
            $lobby->id
        )
        ->where(
            'telegram_user_id',
            $telegramUser->id
        )
        ->exists();

        if ($already) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "⚠️ Вы уже в этом лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже в другом лобби
        |--------------------------------------------------------------------------
        */

        $inAnotherLobby = LobbyPlayer::where(
            'telegram_user_id',
            $telegramUser->id
        )
        ->whereHas(
            'lobby',
            function ($query) {
                $query->whereIn(
                    'status',
                    ['waiting', 'playing']
                );
            }
        )
        ->exists();

        if ($inAnotherLobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "⚠️ Вы уже находитесь в другом лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Добавляем игрока
        |--------------------------------------------------------------------------
        */

        LobbyPlayer::create([
            'lobby_id' => $lobby->id,
            'telegram_user_id' => $telegramUser->id,
            'ready' => false
        ]);

        /*
        |--------------------------------------------------------------------------
        | Обновляем лобби
        |--------------------------------------------------------------------------
        */

        $lobby->refresh();

        $lobby->load([
            'players.telegramUser',
            'creator'
        ]);

        $lobby->loadCount('players');

        /*
        |--------------------------------------------------------------------------
        | Уведомления
        |--------------------------------------------------------------------------
        */

        $this->notifyPlayers(
            $lobby,
            $telegram,
            $telegramUser
        );

        /*
        |--------------------------------------------------------------------------
        | Информация
        |--------------------------------------------------------------------------
        */

        $this->sendLobbyInfo(
            $chatId,
            $lobby,
            $telegram
        );

        /*
        |--------------------------------------------------------------------------
        | Код игры
        |--------------------------------------------------------------------------
        */

        $this->sendGameAccess(
            $chatId,
            $lobby,
            $telegram
        );

        /*
        |--------------------------------------------------------------------------
        | Приглашение
        |--------------------------------------------------------------------------
        */

        $this->sendLobbyInvite(
            $chatId,
            $lobby,
            $telegram
        );

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Главное меню
    |--------------------------------------------------------------------------
    */

    private function sendMainMenu(
        $chatId,
        $telegram
    ) {

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "👇 Выберите действие:",

            'reply_markup' => json_encode([
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
                    ]
                ],

                'resize_keyboard' => true
            ])
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Информация о лобби
    |--------------------------------------------------------------------------
    |
    | ВАЖНО:
    | Здесь больше НЕ используется GameProfile.
    | Игрок отображается только через Telegram:
    |
    | Имя
    | @username
    |
    */

    private function sendLobbyInfo(
        $chatId,
        $lobby,
        $telegram
    ) {

        $playersText = '';

        $players = $lobby->players()
            ->with('telegramUser')
            ->get();

        foreach ($players as $index => $player) {

            $telegramUser =
                $player->telegramUser;

            if (!$telegramUser) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | FIRST NAME
            |--------------------------------------------------------------------------
            */

            $firstName =
                trim($telegramUser->first_name ?? '');

            if ($firstName === '') {
                $firstName = 'Пользователь';
            }

            /*
            |--------------------------------------------------------------------------
            | USERNAME
            |--------------------------------------------------------------------------
            */

            $username =
                trim($telegramUser->username ?? '');

            if ($username !== '') {

                $telegramName =
                    "@{$username}";

            } else {

                $telegramName =
                    $firstName;
            }

            $playersText .=
                ($index + 1) .
                ". 👤 {$firstName}\n" .
                "   {$telegramName}\n\n";
        }

        if ($playersText === '') {
            $playersText =
                "Пока игроков нет.\n";
        }

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
    "🏠 Лобби #{$lobby->id}\n\n" .
    "👥 {$players->count()}/{$lobby->max_players} игроков\n\n" .
    $playersText,

            'reply_markup' => json_encode([
                'keyboard' => [
                    [
                        [
                            'text' =>
                                '🚪 Выйти из лобби'
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
    }


    /*
    |--------------------------------------------------------------------------
    | Код игры
    |--------------------------------------------------------------------------
    */

    private function sendGameAccess(
        $chatId,
        $lobby,
        $telegram
    ) {

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "🎮 Игра готова!\n\n" .
                "🆔 Лобби #{$lobby->id}\n\n" .
                "Удачной игры!\n" .
                "👇 Код комнаты:"
        ]);

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                $lobby->game_room_code
        ]);

        $gameLink =
            "https://play.suspects.io/?code=" .
            $lobby->game_room_code;

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "Нажмите ссылку и сыграйте " .
                "в Suspects вместе с пользователями лобби!\n\n" .
                $gameLink
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | Уведомление игроков
    |--------------------------------------------------------------------------
    |
    | Здесь также полностью убираем игровой ник.
    | Используем first_name / username Telegram.
    |
    */

    private function notifyPlayers(
        $lobby,
        $telegram,
        $newPlayer
    ) {

        /*
        |--------------------------------------------------------------------------
        | Имя нового игрока
        |--------------------------------------------------------------------------
        */

        $firstName =
            trim($newPlayer->first_name ?? '');

        if ($firstName === '') {
            $firstName = 'Пользователь';
        }

        $username =
            trim($newPlayer->username ?? '');

        if ($username !== '') {

            $playerName =
                "{$firstName} (@{$username})";

        } else {

            $playerName =
                $firstName;
        }

        $playersCount =
            $lobby->players()->count();

        $message =
            "👤 Новый игрок присоединился!\n\n" .
            "👤 {$playerName}\n" .
            "🎮 Лобби #{$lobby->id}\n" .
            "👥 Игроки: {$playersCount}/{$lobby->max_players}";

        /*
        |--------------------------------------------------------------------------
        | Уведомляем хоста
        |--------------------------------------------------------------------------
        */

        if (
            $lobby->creator_id !=
            $newPlayer->id
        ) {

            $creatorTelegramId =
                $lobby->creator->telegram_id ?? null;

            if ($creatorTelegramId) {

                try {

                    $telegram->sendMessage([
                        'chat_id' =>
                            $creatorTelegramId,

                        'text' =>
                            $message
                    ]);

                } catch (\Throwable $e) {
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Уведомляем остальных игроков
        |--------------------------------------------------------------------------
        */

        foreach ($lobby->players as $player) {

            if (
                $player->telegram_user_id ==
                $newPlayer->id
            ) {
                continue;
            }

            if (
                $player->telegram_user_id ==
                $lobby->creator_id
            ) {
                continue;
            }

            $playerTelegramId =
                $player->telegramUser->telegram_id
                ?? null;

            if (!$playerTelegramId) {
                continue;
            }

            try {

                $telegram->sendMessage([
                    'chat_id' =>
                        $playerTelegramId,

                    'text' =>
                        $message
                ]);

            } catch (\Throwable $e) {
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Приглашение в лобби
    |--------------------------------------------------------------------------
    */

   private function sendLobbyInvite(
    $chatId,
    $lobby,
    $telegram
) {
    /*
    |--------------------------------------------------------------------------
    | Количество игроков
    |--------------------------------------------------------------------------
    */

    $playersCount = $lobby->players()->count();

    /*
    |--------------------------------------------------------------------------
    | Deep Link
    |--------------------------------------------------------------------------
    */

    $lobbyLink =
        "https://t.me/YkSUS10_bot?start=lobby_{$lobby->id}";

    /*
    |--------------------------------------------------------------------------
    | Создатель
    |--------------------------------------------------------------------------
    */

    $creatorName = 'Игрок';

    if ($lobby->creator->username) {

        $creatorName =
            '@' . $lobby->creator->username;

    } elseif ($lobby->creator->first_name) {

        $creatorName =
            $lobby->creator->first_name;
    }

    /*
    |--------------------------------------------------------------------------
    | Статус
    |--------------------------------------------------------------------------
    */

    if ($lobby->status === 'playing') {

        $statusText =
            "🔴 Игра уже началась";

    } else {

        $statusText =
            "🟢 Ищем игроков";
    }

    /*
    |--------------------------------------------------------------------------
    | Приглашение
    |--------------------------------------------------------------------------
    */

    $inviteText =
        "🎮 Приглашение в лобби\n\n" .
        "👑 Создал: {$creatorName}\n" .
        "👥 Игроки: {$playersCount}/{$lobby->max_players}\n" .
        "{$statusText}";

    /*
    |--------------------------------------------------------------------------
    | Отправляем приглашение
    |--------------------------------------------------------------------------
    */

    $telegram->sendMessage([
        'chat_id' => $chatId,

        'text' => $inviteText,

        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    [
                        'text' => '🚪 Войти в лобби',
                        'url' => $lobbyLink
                    ]
                ]
            ]
        ])
    ]);
}
}
