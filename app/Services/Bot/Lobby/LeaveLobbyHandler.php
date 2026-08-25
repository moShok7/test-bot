<?php

namespace App\Services\Bot\Lobby;

use App\Models\LobbyPlayer;

class LeaveLobbyHandler
{
    public function __construct(
        protected LobbyService $lobbyService
    ) {}

    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        if ($text !== '🚪 Выйти из лобби') {
            return false;
        }

        $telegramId = $message->from->id ?? null;
        $chatId = $message->chat->id;

        if (!$telegramId) {
            return false;
        }

        $player = LobbyPlayer::whereHas(
            'telegramUser',
            function ($query) use ($telegramId) {
                $query->where('telegram_id', $telegramId);
            }
        )
        ->whereHas(
            'lobby',
            function ($query) {
                $query->whereIn('status', [
                    'waiting',
                    'playing'
                ]);
            }
        )
        ->first();

        if (!$player) {

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ Вы сейчас не состоите в лобби."
            ]);

            return true;
        }

        $lobby = $player->lobby;

        /*
        |--------------------------------------------------------------------------
        | Запрет выхода во время игры
        |--------------------------------------------------------------------------
        */

        if ($lobby->status === 'playing') {

            if (
                !$lobby->started_at ||
                $lobby->started_at->gt(now()->subMinutes(5))
            ) {

                $telegram->sendMessage([
                    'chat_id' => $chatId,

                    'text' =>
                        "🎮 Игра уже началась.\n\n" .
                        "🚫 Покинуть лобби можно через 5 минут после начала игры."
                ]);

                return true;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем информацию об игроке ДО удаления
        |--------------------------------------------------------------------------
        */

        $gameProfile = $player->telegramUser->gameProfile ?? null;

        $nickname =
            $gameProfile?->game_nickname
            ?? $player->telegramUser->username
            ?? $player->telegramUser->first_name
            ?? 'Игрок';

        /*
        |--------------------------------------------------------------------------
        | Проверяем, был ли игрок хостом
        |--------------------------------------------------------------------------
        */

        $wasHost =
            $lobby->creator_id == $player->telegram_user_id;

        /*
        |--------------------------------------------------------------------------
        | Удаляем игрока
        |--------------------------------------------------------------------------
        */

        $player->delete();

        /*
        |--------------------------------------------------------------------------
        | Если вышел хост
        |--------------------------------------------------------------------------
        */

        $newHost = null;

        if ($wasHost) {

            $newHost = LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )
            ->orderBy('id')
            ->first();

            /*
            |--------------------------------------------------------------------------
            | Передаём лобби новому хосту
            |--------------------------------------------------------------------------
            */

            if ($newHost) {

                $lobby->update([
                    'creator_id' =>
                        $newHost->telegram_user_id
                ]);

                $telegram->sendMessage([
                    'chat_id' =>
                        $newHost->telegramUser->telegram_id,

                    'text' =>
                        "👑 Вы стали новым хостом!\n\n" .
                        "🎮 Лобби #{$lobby->id}\n" .
                        "🔑 Код комнаты: {$lobby->game_room_code}\n\n" .
                        "Теперь вы управляете лобби."
                ]);

            } else {

                /*
                |--------------------------------------------------------------------------
                | Игроков больше нет — закрываем лобби
                |--------------------------------------------------------------------------
                |
                | LobbyService::close():
                | - удалит уведомления "🔔 Новое лобби!"
                | - удалит записи lobby_notifications
                | - поставит статус closed
                |
                */

                $this->lobbyService->close(
                    $lobby,
                    'Все игроки вышли из лобби.'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Количество оставшихся игроков
        |--------------------------------------------------------------------------
        */

        $playersCount = LobbyPlayer::where(
            'lobby_id',
            $lobby->id
        )->count();

        /*
        |--------------------------------------------------------------------------
        | Уведомляем оставшихся игроков
        |--------------------------------------------------------------------------
        */

        if ($lobby->status !== 'closed') {

            $remainingPlayers = LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )
            ->with('telegramUser')
            ->get();

            foreach ($remainingPlayers as $remainingPlayer) {

                $telegram->sendMessage([
                    'chat_id' =>
                        $remainingPlayer->telegramUser->telegram_id,

                    'text' =>
                        "🚪 Игрок вышел из лобби!\n\n" .
                        "🎮 {$nickname}\n" .
                        "🎮 Лобби #{$lobby->id}\n" .
                        "👥 Игроки: {$playersCount}/{$lobby->max_players}"
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Сообщение вышедшему игроку
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([

            'chat_id' => $chatId,

            'text' =>
                "🚪 Вы вышли из лобби.",

            'reply_markup' => json_encode([

                'keyboard' => [

                    [
                        [
                            'text' => '🎮 Игровое меню'
                        ]
                    ],

                    [
                        [
                            'text' => '⬅️ Главное меню'
                        ]
                    ]

                ],

                'resize_keyboard' => true

            ])

        ]);

        return true;
    }
}