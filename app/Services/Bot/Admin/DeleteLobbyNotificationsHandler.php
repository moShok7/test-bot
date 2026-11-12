<?php

namespace App\Services\Bot\Admin;

use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Services\Telegram\UserTelegramService;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class DeleteLobbyNotificationsHandler
{
    private const ADMIN_ID = 6537808029;

    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));
        $userId = (int) ($message->from->id ?? 0);
        $chatId = $message->chat->id ?? null;

        // Проверяем, что это команда /del
        if (!preg_match('/^\/del(?:@\w+)?(?:\s+(\d+))?$/i', $text, $matches)) {
            return false;
        }

        // Только разрешённый пользователь
        if ($userId !== self::ADMIN_ID) {
            return true;
        }

        if (empty($matches[1])) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ Укажи ID лобби.\n\nПример: /del 125",
            ]);

            return true;
        }

        $lobbyId = (int) $matches[1];

        $lobby = Lobby::find($lobbyId);

        if (!$lobby) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ Лобби #{$lobbyId} не найдено.",
            ]);

            return true;
        }

        $notifications = LobbyNotification::where('lobby_id', $lobbyId)->get();

        if ($notifications->isEmpty()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "ℹ️ У лобби #{$lobbyId} нет сохранённых рассылок.",
            ]);

            return true;
        }

        $deleted = 0;
        $failed = 0;

        $userTelegram = app(UserTelegramService::class);

        foreach ($notifications as $notification) {
            try {
                $messageId = (int) $notification->telegram_message_id;
                $targetChatId = $notification->chat_id;

                if ($messageId <= 0 || !$targetChatId) {
                    throw new \RuntimeException('Некорректный chat_id или message_id');
                }

                if ($notification->chat_type === 'mtproto_group') {
                    // Удаление через MTProto
                    $userTelegram->deleteMessage(
                        $targetChatId,
                        $messageId,
                        'supergroup'
                    );
                } else {
                    // Удаление через Telegram Bot API
                    $telegram->deleteMessage([
                        'chat_id' => $targetChatId,
                        'message_id' => $messageId,
                    ]);
                }

                // Удаляем запись только после успешного удаления сообщения
                $notification->delete();

                $deleted++;

            } catch (\Throwable $e) {
                $failed++;

                Log::warning('Не удалось удалить рассылку лобби', [
                    'lobby_id' => $lobbyId,
                    'notification_id' => $notification->id,
                    'chat_id' => $notification->chat_id,
                    'message_id' => $notification->telegram_message_id,
                    'chat_type' => $notification->chat_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🗑 <b>Удаление рассылки завершено</b>\n\n" .
                "Лобби: #{$lobbyId}\n" .
                "Удалено сообщений: {$deleted}\n" .
                "Ошибок: {$failed}\n\n" .
                "Само лобби и его данные сохранены.",
            'parse_mode' => 'HTML',
        ]);

        return true;
    }
}