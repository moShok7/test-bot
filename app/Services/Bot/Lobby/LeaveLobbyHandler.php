<?php

namespace App\Services\Bot\Lobby;

use App\Models\LobbyPlayer;
use App\Models\LobbyNotification;

class LeaveLobbyHandler
{
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

        /*
        |--------------------------------------------------------------------------
        | Находим игрока в активном лобби
        |--------------------------------------------------------------------------
        */

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
                'text' => '❌ Вы сейчас не состоите в лобби.'
            ]);

            return true;
        }

        $lobby = $player->lobby;

        /*
        |--------------------------------------------------------------------------
        | Запрет выхода в первые 5 минут игры
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

        if ($wasHost) {

            $newHost = LobbyPlayer::where(
                'lobby_id',
                $lobby->id
            )
            ->orderBy('id')
            ->first();

            /*
            |--------------------------------------------------------------------------
            | Передаём хостство следующему игроку
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
                | Игроков больше нет
                |--------------------------------------------------------------------------
                |
                | Удаляем все уведомления "🔔 Новое лобби!"
                | и закрываем лобби.
                |--------------------------------------------------------------------------
                */

                $notifications = LobbyNotification::where(
                    'lobby_id',
                    $lobby->id
                )->get();

                foreach ($notifications as $notification) {

                    $telegramUser = $notification->telegramUser;

                    if (!$telegramUser) {
                        continue;
                    }

                    try {

                        $telegram->deleteMessage([
                            'chat_id' =>
                                $telegramUser->telegram_id,

                            'message_id' =>
                                $notification->telegram_message_id,
                        ]);

                    } catch (\Throwable $e) {

                        \Log::warning(
                            'Не удалось удалить уведомление о лобби',
                            [
                                'lobby_id' =>
                                    $lobby->id,

                                'telegram_id' =>
                                    $telegramUser->telegram_id,

                                'message_id' =>
                                    $notification->telegram_message_id,

                                'error' =>
                                    $e->getMessage()
                            ]
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Удаляем записи уведомлений
                |--------------------------------------------------------------------------
                */

                LobbyNotification::where(
                    'lobby_id',
                    $lobby->id
                )->delete();

                /*
                |--------------------------------------------------------------------------
                | Закрываем лобби
                |--------------------------------------------------------------------------
                */

                $lobby->update([
                    'status' => 'closed'
                ]);
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

                if (!$remainingPlayer->telegramUser) {
                    continue;
                }

                try {

                    $telegram->sendMessage([
                        'chat_id' =>
                            $remainingPlayer->telegramUser->telegram_id,

                        'text' =>
                            "🚪 Игрок вышел из лобби!\n\n" .
                            "🎮 {$nickname}\n" .
                            "🎮 Лобби #{$lobby->id}\n" .
                            "👥 Игроки: {$playersCount}/{$lobby->max_players}"
                    ]);

                } catch (\Throwable $e) {

                    \Log::warning(
                        'Не удалось уведомить игроков о выходе',
                        [
                            'lobby_id' =>
                                $lobby->id,

                            'error' =>
                                $e->getMessage()
                        ]
                    );
                }
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