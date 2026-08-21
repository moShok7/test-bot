<?php

namespace App\Services\Bot\Lobby;

use App\Models\TelegramUser;
use App\Models\Lobby;

class PlayersHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');

        /*
        |--------------------------------------------------------------------------
        | Проверяем кнопку
        |--------------------------------------------------------------------------
        */

        if ($text !== '👥 Игроки') {
            return false;
        }

        $telegramId = $message->from->id ?? null;
        $chatId = $message->chat->id ?? null;

        if (!$telegramId || !$chatId) {
            return false;
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

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Пользователь не найден.\n\n" .
                    "Нажмите /start."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем лобби пользователя
        |--------------------------------------------------------------------------
        */

        $lobby = Lobby::whereIn(
            'status',
            [
                'waiting',
                'playing'
            ]
        )
        ->whereHas(
            'players',
            function ($q) use ($telegramUser) {

                $q->where(
                    'telegram_user_id',
                    $telegramUser->id
                );

            }
        )
        ->with([
            'players.telegramUser'
        ])
        ->withCount('players')
        ->first();

        /*
        |--------------------------------------------------------------------------
        | Лобби не найдено
        |--------------------------------------------------------------------------
        */

        if (!$lobby) {

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Вы не состоите в лобби."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Формируем список игроков
        |--------------------------------------------------------------------------
        */

        $players = '';

        foreach ($lobby->players as $index => $player) {

            $user = $player->telegramUser;

            if (!$user) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | FIRST NAME
            |--------------------------------------------------------------------------
            */

            $firstName = trim(
                $user->first_name ?? ''
            );

            if ($firstName === '') {
                $firstName = 'Пользователь';
            }

            /*
            |--------------------------------------------------------------------------
            | USERNAME
            |--------------------------------------------------------------------------
            */

            $username = trim(
                $user->username ?? ''
            );

            if ($username !== '') {

                $telegramName =
                    '@' . $username;

                $displayName =
                    $firstName .
                    ' (' .
                    $telegramName .
                    ')';

            } else {

                $displayName =
                    $firstName;
            }

            /*
            |--------------------------------------------------------------------------
            | Проверяем хоста
            |--------------------------------------------------------------------------
            */

            $isHost =
                $player->telegram_user_id ==
                $lobby->creator_id;

            /*
            |--------------------------------------------------------------------------
            | Добавляем игрока
            |--------------------------------------------------------------------------
            */

            if ($isHost) {

                $players .=
                    ($index + 1) .
                    ". 👑 " .
                    $displayName .
                    " • Хост\n";

            } else {

                $players .=
                    ($index + 1) .
                    ". 👤 " .
                    $displayName .
                    "\n";
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Отправляем список
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "👥 Игроки лобби #{$lobby->id}\n\n" .
                $players .
                "\n" .
                "Всего: {$lobby->players_count}/{$lobby->max_players}"
        ]);

        return true;
    }
}