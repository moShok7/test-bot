<?php

namespace App\Jobs;

use App\Models\BotGroup;
use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Models\TelegramUser;
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
         * Если лобби уже закрыто —
         * ничего не отправляем.
         */
        if ($lobby->status !== 'waiting') {
            return;
        }

        /*
         * =========================================================
         * ТЕКСТ УВЕДОМЛЕНИЯ
         * =========================================================
         */

        $text =
            "🔔 Новое лобби!\n\n" .
            "🎮 Лобби #{$lobby->id}\n" .
            "👑 Создал: {$this->creatorName}\n" .
            "👥 Игроки: {$this->count}/{$lobby->max_players}\n" .
            "⏳ Ожидание игроков\n\n" .
            "Хочешь присоединиться?";

        $replyMarkup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🚪 Войти в лобби',
                        'callback_data' => 'join_lobby_' . $lobby->id,
                    ],
                ],
            ],
        ];

        /*
         * =========================================================
         * 1. ЛИЧНЫЕ УВЕДОМЛЕНИЯ
         * =========================================================
         */

        $activePlayerIds = \App\Models\LobbyPlayer::whereHas(
            'lobby',
            function ($query) {
                $query->whereIn('status', [
                    'waiting',
                    'playing',
                ]);
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
                        'telegram_id' => $notifyUser->telegram_id,
                        'lobby_id' => $lobby->id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        /*
         * =========================================================
         * 2. УВЕДОМЛЕНИЯ В ГРУППЫ
         * =========================================================
         */

        $groups = BotGroup::query()
            ->where('is_active', true)
            ->get();

        foreach ($groups as $group) {
            try {
                $telegram->sendMessage([
                    'chat_id' => $group->chat_id,
                    'text' => $text,
                    'reply_markup' => json_encode($replyMarkup),
                ]);

                Log::info(
                    'Уведомление о новом лобби отправлено в группу',
                    [
                        'group_id' => $group->chat_id,
                        'group_title' => $group->title,
                        'lobby_id' => $lobby->id,
                    ]
                );

            } catch (\Throwable $e) {
                Log::warning(
                    'Не удалось отправить уведомление о новом лобби в группу',
                    [
                        'group_id' => $group->chat_id,
                        'group_title' => $group->title,
                        'lobby_id' => $lobby->id,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }
    }
}