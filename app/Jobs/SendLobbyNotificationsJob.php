<?php

namespace App\Jobs;

use App\Models\BotGroup;
use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Models\TelegramUser;
use App\Models\LobbyPlayer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendLobbyNotificationsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $lobbyId,
        public int $creatorTelegramId,
        public string $creatorName,
        public int $count,
    ) {}

    public function handle(Api $telegram): void
    {
        $lobby = Lobby::find($this->lobbyId);

        if (!$lobby) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | УДАЛЯЕМ СТАРЫЕ НЕАКТУАЛЬНЫЕ УВЕДОМЛЕНИЯ
        |--------------------------------------------------------------------------
        */

        $this->deleteOldNotifications($telegram);

        /*
        |--------------------------------------------------------------------------
        | Если лобби уже закрыто — ничего не отправляем
        |--------------------------------------------------------------------------
        */

        if ($lobby->status !== 'waiting') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Если код игры отсутствует — уведомление не отправляем
        |--------------------------------------------------------------------------
        */

        if (empty($lobby->game_room_code)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Прямая ссылка в игру
        |--------------------------------------------------------------------------
        */

        $gameLink =
            'https://play.suspects.io/?code=' .
            $lobby->game_room_code;

        /*
        |--------------------------------------------------------------------------
        | Имя создателя
        |--------------------------------------------------------------------------
        */

        $creatorLink = "tg://user?id={$this->creatorTelegramId}";

        $creatorName = htmlspecialchars(
            $this->creatorName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        /*
        |--------------------------------------------------------------------------
        | Текст уведомления
        |--------------------------------------------------------------------------
        */

        $text =
            "🔔 Новое лобби!\n\n" .
            "🎮 Лобби #{$lobby->id}\n" .
            "👑 Создал: <a href=\"{$creatorLink}\"><b>{$creatorName}</b></a>\n" .
            "🔑 Код: {$lobby->game_room_code}\n\n" .
            "👇 Вход в игру:";

        /*
        |--------------------------------------------------------------------------
        | Кнопки
        |--------------------------------------------------------------------------
        */

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🎮 Войти в игру',
                        'url' => $gameLink,
                    ],
                ],
                [
                    [
                        'text' => '📋 Скопировать код',
                        'copy_text' => [
                            'text' => $lobby->game_room_code,
                        ],
                    ],
                ],
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | 1. ЛИЧНЫЕ УВЕДОМЛЕНИЯ
        |--------------------------------------------------------------------------
        */

        $activePlayerIds = LobbyPlayer::whereHas(
            'lobby',
            function ($query) {
                $query->whereIn(
                    'status',
                    [
                        'waiting',
                        'playing',
                    ]
                );
            }
        )
            ->pluck('telegram_user_id')
            ->unique()
            ->toArray();

        $query = TelegramUser::query()
            ->where(
                'telegram_id',
                '!=',
                $this->creatorTelegramId
            );

        if (!empty($activePlayerIds)) {
            $query->whereNotIn(
                'id',
                $activePlayerIds
            );
        }

        $notifyUsers = $query->get();

        foreach ($notifyUsers as $notifyUser) {

            try {

                $response = $telegram->sendMessage([
                    'chat_id' => $notifyUser->telegram_id,

                    'text' => $text,

                    'parse_mode' => 'HTML',

                    'reply_markup' => json_encode($replyMarkup),
                ]);

                LobbyNotification::create([
                    'lobby_id' => $lobby->id,

                    'telegram_user_id' => $notifyUser->id,

                    'telegram_message_id' => $response->getMessageId(),
                ]);

            } catch (\Throwable $e) {

                Log::warning(
                    'Не удалось отправить уведомление о новом лобби',
                    [
                        'telegram_id' =>
                            $notifyUser->telegram_id,

                        'lobby_id' =>
                            $lobby->id,

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. УВЕДОМЛЕНИЯ В ГРУППЫ
        |--------------------------------------------------------------------------
        */

        $groups = BotGroup::query()
            ->where(
                'is_active',
                true
            )
            ->get();

        foreach ($groups as $group) {

            try {
                $telegram->sendMessage([
                    'chat_id' => $group->chat_id,

                    'text' => $text,

                    'parse_mode' => 'HTML',

                    'reply_markup' => json_encode($replyMarkup),
                ]);

                Log::info(
                    'Уведомление о новом лобби отправлено в группу',
                    [
                        'group_id' =>
                            $group->chat_id,

                        'group_title' =>
                            $group->title,

                        'lobby_id' =>
                            $lobby->id,
                    ]
                );

            } catch (\Throwable $e) {

                Log::warning(
                    'Не удалось отправить уведомление о новом лобби в группу',
                    [
                        'group_id' =>
                            $group->chat_id,

                        'group_title' =>
                            $group->title,

                        'lobby_id' =>
                            $lobby->id,

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Удаление старых уведомлений
    |--------------------------------------------------------------------------
    */

    private function deleteOldNotifications(Api $telegram): void
    {
        $oldNotifications = LobbyNotification::query()
            ->whereHas(
                'lobby',
                function ($query) {
                    $query->where('status', '!=', 'waiting');
                }
            )
            ->get();

        foreach ($oldNotifications as $notification) {

            try {

                /*
                |--------------------------------------------------------------------------
                | Получаем Telegram ID пользователя
                |--------------------------------------------------------------------------
                */

                $user = TelegramUser::find(
                    $notification->telegram_user_id
                );

                if (
                    $user &&
                    $notification->telegram_message_id
                ) {
                    $telegram->deleteMessage([
                        'chat_id' =>
                            $user->telegram_id,

                        'message_id' =>
                            $notification->telegram_message_id,
                    ]);
                }

            } catch (\Throwable $e) {

                /*
                |--------------------------------------------------------------------------
                | Сообщение уже могло быть удалено вручную.
                | Это не должно ломать Job.
                |--------------------------------------------------------------------------
                */

                Log::debug(
                    'Не удалось удалить старое уведомление',
                    [
                        'notification_id' =>
                            $notification->id,

                        'error' =>
                            $e->getMessage(),
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Удаляем запись из БД в любом случае
            |--------------------------------------------------------------------------
            */

            $notification->delete();
        }
    }
}