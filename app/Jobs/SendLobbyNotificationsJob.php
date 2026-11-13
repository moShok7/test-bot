<?php

namespace App\Jobs;

use App\Models\BotGroup;
use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Models\LobbyPlayer;
use App\Models\TelegramUser;
use App\Services\Telegram\UserTelegramGroupsService;
use App\Services\Telegram\UserTelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class SendLobbyNotificationsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    /**
     * Время блокировки от повторного запуска одной первоначальной рассылки.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'lobby_initial_notifications_' . $this->lobbyId;
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(
        public int $lobbyId,
        public ?int $creatorTelegramId,
        public string $creatorName,
        public int $count = 100,
    ) {
        $this->onQueue('telegram');
    }

    /**
     * Проверяет, создал ли тот же участник более новое лобби
     * с другим игровым кодом.
     */
    private function hasNewerLobby(Lobby $lobby): bool
    {
        if (!$lobby->creator_id || !$lobby->game_room_code) {
            return false;
        }

        return Lobby::query()
            ->where('creator_id', $lobby->creator_id)
            ->where('id', '>', $lobby->id)
            ->where('game_room_code', '!=', $lobby->game_room_code)
            ->exists();
    }

    /**
     * Удаляет одно отправленное сообщение.
     */
    private function deleteTelegramMessage(
        Api $telegram,
        UserTelegramService $userTelegram,
        string $chatId,
        int $messageId,
        string $chatType
    ): void {
        if ($chatType === 'mtproto_group') {
            $userTelegram->deleteMessage(
                $chatId,
                $messageId,
                'supergroup'
            );

            return;
        }

        $telegram->deleteMessage([
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Удаляет уведомления всех предыдущих лобби этого создателя,
     * если их игровой код отличается от текущего.
     */
    private function deletePreviousCreatorNotifications(
        Api $telegram,
        UserTelegramService $userTelegram,
        Lobby $currentLobby
    ): void {
        if (
            !$currentLobby->creator_id ||
            !$currentLobby->game_room_code
        ) {
            return;
        }

        $notifications = LobbyNotification::query()
            ->whereHas('lobby', function ($query) use ($currentLobby) {
                $query
                    ->where('creator_id', $currentLobby->creator_id)
                    ->where('id', '!=', $currentLobby->id)
                    ->where(
                        'game_room_code',
                        '!=',
                        $currentLobby->game_room_code
                    );
            });

        $deletedCount = 0;
        $failedCount = 0;

        $notifications->chunkById(100, function ($items) use (
            $telegram,
            $userTelegram,
            &$deletedCount,
            &$failedCount
        ) {
            foreach ($items as $notification) {
                try {
                    if (
                        empty($notification->chat_id) ||
                        empty($notification->telegram_message_id)
                    ) {
                        $notification->delete();
                        $deletedCount++;
                        continue;
                    }

                    $this->deleteTelegramMessage(
                        $telegram,
                        $userTelegram,
                        (string) $notification->chat_id,
                        (int) $notification->telegram_message_id,
                        $notification->chat_type
                    );

                    $notification->delete();
                    $deletedCount++;

                } catch (Throwable $e) {
                    $failedCount++;

                    Log::warning('Не удалось удалить старую рассылку создателя', [
                        'notification_id' => $notification->id,
                        'lobby_id' => $notification->lobby_id,
                        'chat_id' => $notification->chat_id,
                        'chat_type' => $notification->chat_type,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Очистка старых рассылок создателя завершена', [
            'creator_id' => $currentLobby->creator_id,
            'current_lobby_id' => $currentLobby->id,
            'current_code' => $currentLobby->game_room_code,
            'deleted_notifications' => $deletedCount,
            'failed_notifications' => $failedCount,
        ]);
    }

    public function handle(
        Api $telegram,
        UserTelegramService $userTelegram,
        UserTelegramGroupsService $userTelegramGroups
    ): void {
        $lobby = Lobby::with('clan')->find($this->lobbyId);

        if (
            !$lobby ||
            $lobby->status !== 'waiting' ||
            empty($lobby->game_room_code)
        ) {
            Log::info('Рассылка пропущена: лобби недоступно', [
                'lobby_id' => $this->lobbyId,
                'status' => $lobby?->status,
            ]);

            return;
        }

        // Проверяем, не устарело ли лобби.
        if ($this->hasNewerLobby($lobby)) {
            Log::info('Рассылка отменена: уже создано новое лобби', [
                'lobby_id' => $lobby->id,
                'creator_id' => $lobby->creator_id,
                'code' => $lobby->game_room_code,
            ]);

            return;
        }

        // Если создатель не передан, пробуем получить его из creator_id.
        $creator = null;

        if ($lobby->creator_id) {
            $creator = TelegramUser::find($lobby->creator_id);
        }

        $creatorTelegramId = $this->creatorTelegramId
            ?: ($creator?->telegram_id
                ? (int) $creator->telegram_id
                : null);

        $creatorName = $this->creatorName;

        if (
            $creatorName === '' ||
            $creatorName === 'Неизвестно' ||
            $creatorName === 'Игрок'
        ) {
            $creatorName = $creator?->username
                ? '@' . $creator->username
                : ($creator?->first_name ?: 'Игрок');
        }

        $safeCreatorName = htmlspecialchars(
            $creatorName,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $creatorLink = $creatorTelegramId
            ? 'tg://user?id=' . $creatorTelegramId
            : null;

        $creatorLine = $creatorLink
            ? "👑 Создал: <a href=\"{$creatorLink}\"><b>{$safeCreatorName}</b></a>\n"
            : "👑 Создал: <b>{$safeCreatorName}</b>\n";

        // Удаляем предыдущие рассылки этого же создателя.
        $this->deletePreviousCreatorNotifications(
            $telegram,
            $userTelegram,
            $lobby
        );

        // Дополнительно удаляем уведомления завершённых лобби.
        $this->deleteOldNotifications($telegram, $userTelegram);

        $lobby->refresh();

        if (
            $lobby->status !== 'waiting' ||
            empty($lobby->game_room_code) ||
            $this->hasNewerLobby($lobby)
        ) {
            Log::info('Рассылка остановлена после очистки', [
                'lobby_id' => $lobby->id,
                'status' => $lobby->status,
            ]);

            return;
        }

        // Личные уведомления отправляются отдельными пакетами.
        $this->dispatchPrivateBatches(
            $lobby,
            $creatorTelegramId,
            $creatorName
        );

        $gameLink = 'https://play.suspects.io/?code='
            . rawurlencode($lobby->game_room_code);

        $roomCode = htmlspecialchars(
            $lobby->game_room_code,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $clanLabel = $lobby->clan
            ? ' [' . htmlspecialchars(
                $lobby->clan->name,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ) . ']'
            : '';

        $text = "🔔 Новое лобби!\n\n"
            . "🎮 Лобби #{$lobby->id}{$clanLabel}\n"
            . $creatorLine
            . "🔑 Код: {$roomCode}\n\n"
            . '👇 Вход в игру:';

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

        // -------------------------------------------------
        // 1. Группы через Bot API.
        // -------------------------------------------------

        $groups = BotGroup::query()
            ->where('is_active', true)
            ->get();

        foreach ($groups as $group) {

            // Не отправляем старый код, если появилось новое лобби.
            if ($this->hasNewerLobby($lobby)) {
                Log::info('Групповая рассылка Bot API остановлена', [
                    'lobby_id' => $lobby->id,
                    'creator_id' => $lobby->creator_id,
                ]);

                return;
            }

            $chatId = (string) $group->chat_id;

            if ($this->notificationExists(
                $lobby->id,
                $chatId,
                'group'
            )) {
                continue;
            }

            try {
                $response = $telegram->sendMessage([
                    'chat_id' => $group->chat_id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'reply_markup' => json_encode(
                        $replyMarkup,
                        JSON_UNESCAPED_UNICODE
                    ),
                ]);

                $messageId = $response->getMessageId();

                if (!$messageId) {
                    throw new \RuntimeException(
                        'Telegram не вернул ID сообщения'
                    );
                }

                // Проверяем, не устарело ли лобби во время отправки.
                if ($this->hasNewerLobby($lobby)) {
                    try {
                        $this->deleteTelegramMessage(
                            $telegram,
                            $userTelegram,
                            $chatId,
                            (int) $messageId,
                            'group'
                        );
                    } catch (Throwable $e) {
                        Log::warning('Не удалось удалить устаревшее сообщение Bot API', [
                            'lobby_id' => $lobby->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    return;
                }

                LobbyNotification::create([
                    'lobby_id' => $lobby->id,
                    'telegram_user_id' => null,
                    'telegram_message_id' => $messageId,
                    'chat_id' => $chatId,
                    'chat_type' => 'group',
                ]);

                try {
                    $telegram->pinChatMessage([
                        'chat_id' => $group->chat_id,
                        'message_id' => $messageId,
                        'disable_notification' => true,
                    ]);
                } catch (Throwable $e) {
                    Log::warning('Не удалось закрепить сообщение Bot API', [
                        'lobby_id' => $lobby->id,
                        'chat_id' => $chatId,
                        'error' => $e->getMessage(),
                    ]);
                }

            } catch (Throwable $e) {
                Log::warning('Ошибка отправки в группу Bot API', [
                    'lobby_id' => $lobby->id,
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // -------------------------------------------------
        // 2. Внешние группы через MTProto.
        // -------------------------------------------------

        try {
            $externalGroups = $userTelegramGroups->getExternalGroups();

            foreach ($externalGroups as $group) {

                // Останавливаем старую рассылку.
                if ($this->hasNewerLobby($lobby)) {
                    Log::info('Групповая рассылка MTProto остановлена', [
                        'lobby_id' => $lobby->id,
                        'creator_id' => $lobby->creator_id,
                    ]);

                    return;
                }

                $chatId = (string) $group['chat_id'];

                if ($this->notificationExists(
                    $lobby->id,
                    $chatId,
                    'mtproto_group'
                )) {
                    continue;
                }

                try {
                    $mtprotoText =
                        "🔔 <b>Новое лобби!</b>\n\n"
                        . "🎮 <b>Лобби #{$lobby->id}{$clanLabel}</b>\n"
                        . $creatorLine
                        . "🔑 <b>Код: <code>{$roomCode}</code></b>\n\n"
                        . "👇 <b>Вход в игру:</b>\n"
                        . $gameLink;

                    $messageId = $userTelegram->sendMessage(
                        $group['chat_id'],
                        $mtprotoText
                    );

                    if (!$messageId) {
                        throw new \RuntimeException(
                            'MTProto не вернул ID сообщения'
                        );
                    }

                    // Проверяем актуальность после отправки.
                    if ($this->hasNewerLobby($lobby)) {
                        try {
                            $this->deleteTelegramMessage(
                                $telegram,
                                $userTelegram,
                                $chatId,
                                (int) $messageId,
                                'mtproto_group'
                            );
                        } catch (Throwable $e) {
                            Log::warning('Не удалось удалить устаревшее MTProto сообщение', [
                                'lobby_id' => $lobby->id,
                                'chat_id' => $chatId,
                                'message_id' => $messageId,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        return;
                    }

                    LobbyNotification::create([
                        'lobby_id' => $lobby->id,
                        'telegram_user_id' => null,
                        'telegram_message_id' => $messageId,
                        'chat_id' => $chatId,
                        'chat_type' => 'mtproto_group',
                    ]);

                    try {
                        $userTelegram->pinMessage(
                            $group['chat_id'],
                            $messageId
                        );
                    } catch (Throwable $e) {
                        Log::warning('Не удалось закрепить MTProto сообщение', [
                            'lobby_id' => $lobby->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                } catch (Throwable $e) {
                    Log::warning('Ошибка отправки через MTProto', [
                        'lobby_id' => $lobby->id,
                        'chat_id' => $chatId,
                        'title' => $group['title'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Throwable $e) {
            Log::error('Не удалось получить внешние группы MTProto', [
                'lobby_id' => $lobby->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('Рассылка уведомлений завершена', [
            'lobby_id' => $lobby->id,
            'creator_telegram_id' => $creatorTelegramId,
            'batch_size' => max(1, min($this->count, 100)),
        ]);
    }

    /**
     * Создаёт отдельные Jobs для личных уведомлений.
     */
    private function dispatchPrivateBatches(
        Lobby $lobby,
        ?int $creatorTelegramId,
        string $creatorName
    ): void {
        // Не уведомляем пользователей, которые уже находятся
        // в активных лобби.
        $activePlayerIds = LobbyPlayer::query()
            ->whereHas('lobby', function ($query) {
                $query->whereIn('status', ['waiting', 'playing']);
            })
            ->pluck('telegram_user_id')
            ->unique()
            ->values()
            ->all();

        $query = TelegramUser::query()
            ->whereNotNull('telegram_id')
            ->where('telegram_id', '>', 0)
            ->whereDoesntHave('ban');

        if ($creatorTelegramId) {
            $query->where('telegram_id', '!=', $creatorTelegramId);
        }

        if ($activePlayerIds !== []) {
            $query->whereNotIn('id', $activePlayerIds);
        }

        $batchSize = max(1, min($this->count, 100));

        $query->select('id')
            ->chunkById($batchSize, function ($users) use (
                $lobby,
                $creatorTelegramId,
                $creatorName
            ) {
                $userIds = $users->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($userIds === []) {
                    return;
                }

                SendLobbyNotificationsBatchJob::dispatch(
                    $lobby->id,
                    $creatorTelegramId,
                    $creatorName,
                    $userIds
                )->onQueue('telegram');
            });
    }

    private function notificationExists(
        int $lobbyId,
        string $chatId,
        string $chatType
    ): bool {
        return LobbyNotification::query()
            ->where('lobby_id', $lobbyId)
            ->where('chat_id', $chatId)
            ->where('chat_type', $chatType)
            ->exists();
    }

    /**
     * Удаляет уведомления о лобби, которые больше не ожидают игроков.
     */
    private function deleteOldNotifications(
        Api $telegram,
        UserTelegramService $userTelegram
    ): void {
        LobbyNotification::query()
            ->whereHas('lobby', function ($query) {
                $query->whereIn('status', [
                    'finished',
                    'closed',
                    'cancelled',
                ]);
            })
            ->chunkById(100, function ($notifications) use (
                $telegram,
                $userTelegram
            ) {
                foreach ($notifications as $notification) {
                    try {
                        if (
                            empty($notification->chat_id) ||
                            empty($notification->telegram_message_id)
                        ) {
                            continue;
                        }

                        $this->deleteTelegramMessage(
                            $telegram,
                            $userTelegram,
                            (string) $notification->chat_id,
                            (int) $notification->telegram_message_id,
                            $notification->chat_type
                        );

                    } catch (Throwable $e) {
                        Log::warning('Не удалось удалить старое уведомление', [
                            'notification_id' => $notification->id,
                            'chat_id' => $notification->chat_id,
                            'chat_type' => $notification->chat_type,
                            'error' => $e->getMessage(),
                        ]);

                    } finally {
                        $notification->delete();
                    }
                }
            });
    }
}
