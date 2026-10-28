<?php

namespace App\Services\Bot\Lobby;

use App\Models\TelegramUser;
use App\Models\Lobby;
use Illuminate\Support\Facades\DB;

class HostLobbyHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim($message->text ?? '');
        $telegramId = $message->from->id ?? null;

        if (!$telegramId || $text !== '🎮 Моё лобби') {
            return false;
        }

        $chatId = $message->chat->id ?? $telegramId;

        $user = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$user) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Пользователь не найден. Нажмите /start.',
            ]);

            return true;
        }

        // Обычное активное лобби
        $lobby = Lobby::whereIn('status', ['waiting', 'playing'])
            ->where(function ($query) use ($user) {
                $query->where('creator_id', $user->id)
                    ->orWhereHas('players', function ($q) use ($user) {
                        $q->where('telegram_user_id', $user->id);
                    });
            })
            ->withCount('players')
            ->with(['players.telegramUser', 'creator'])
            ->first();

        // Опубликованное премьерное лобби пользователя
        $premier = DB::table('premier_lobbies')
            ->where('creator_id', $user->id)
            ->where('status', 'published')
            ->orderByDesc('id')
            ->first();

        if (!$lobby && !$premier) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ У вас нет активных лобби.",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '➕ Создать лобби']],
                        [['text' => '🎬 Создать премьерное лобби']],
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        // Показываем обычное лобби
        if ($lobby) {
            $isHost = (int) $lobby->creator_id === (int) $user->id;

            $status = $lobby->status === 'waiting'
                ? '⏳ Ожидание игроков'
                : '🎮 Игра идёт';

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    ($isHost ? "👑 Ваше обычное лобби\n\n" : "🎮 Вы в лобби\n\n") .
                    "🆔 Лобби #{$lobby->id}\n" .
                    "🔑 Код комнаты: {$lobby->game_room_code}\n" .
                    "👥 Игроки: {$lobby->players_count}/{$lobby->max_players}\n" .
                    "📌 Статус: {$status}",
                'reply_markup' => json_encode([
                    'keyboard' => $isHost
                        ? [
                            [['text' => '👥 Игроки']],
                            [['text' => '🎮 Моё лобби']],
                            [['text' => '⬅️ Главное меню']],
                        ]
                        : [
                            [['text' => '👥 Игроки']],
                            [['text' => '📤 Приглашение']],
                            [['text' => '🚪 Выйти из лобби']],
                            [['text' => '⬅️ Главное меню']],
                        ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            // Удалять обычное лобби разрешаем только создателю,
            // пока оно ожидает игроков.
            if ($isHost && $lobby->status === 'waiting') {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Управление обычным лобби:',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[
                            [
                                'text' => '❌ Удалить лобби',
                                'callback_data' => 'host_delete_lobby_' . $lobby->id,
                            ],
                        ]],
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        // Показываем премьерное лобби
        if ($premier) {
            $startsAt = $premier->starts_at
                ? date('d.m.Y H:i', strtotime($premier->starts_at))
                : 'Не указано';

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🎬 Ваше премьерное лобби\n\n" .
                    "🆔 ID: #{$premier->id}\n" .
                    "🎬 Название: {$premier->title}\n" .
                    "📝 Описание: " . ($premier->description ?: 'Не указано') . "\n" .
                    "🗓 Начало: {$startsAt}\n" .
                    "👥 Максимум участников: {$premier->max_players}\n" .
                    "📌 Статус: Опубликовано",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        [
                            'text' => '❌ Удалить премьерное лобби',
                            'callback_data' => 'premier_delete_' . $premier->id,
                        ],
                    ]],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return true;
    }

    /**
     * Обработка inline-кнопок удаления.
     */
    public function handleCallback($callback, $telegram): bool
    {
        $data = $callback->data ?? '';

        $prefixes = [
            'host_delete_lobby_',
            'host_confirm_delete_lobby_',
            'host_cancel_delete_lobby_',
            'premier_delete_',
            'premier_confirm_delete_',
            'premier_cancel_delete_',
        ];

        $matched = false;

        foreach ($prefixes as $prefix) {
            if (str_starts_with($data, $prefix)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return false;
        }

        $telegramId = $callback->from->id ?? null;
        $chatId = $callback->message->chat->id ?? null;

        if (!$telegramId || !$chatId) {
            return true;
        }

        try {
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback->id,
            ]);
        } catch (\Throwable $e) {
            // Продолжаем обработку, если Telegram не ответил на callback.
        }

        $user = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$user) {
            return true;
        }

        // Обычное лобби: запрос на удаление
        if (str_starts_with($data, 'host_delete_lobby_')) {
            $id = substr($data, strlen('host_delete_lobby_'));

            $lobby = Lobby::where('id', $id)
                ->where('creator_id', $user->id)
                ->where('status', 'waiting')
                ->first();

            if (!$lobby) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Лобби не найдено или его нельзя удалить.',
                ]);
                return true;
            }

            $this->askConfirmation(
                $telegram,
                $chatId,
                "Удалить обычное лобби #{$id}?",
                'host_confirm_delete_lobby_' . $id,
                'host_cancel_delete_lobby_' . $id
            );

            return true;
        }

        // Подтверждение удаления обычного лобби
        if (str_starts_with($data, 'host_confirm_delete_lobby_')) {
            $id = substr($data, strlen('host_confirm_delete_lobby_'));

            $deleted = Lobby::where('id', $id)
                ->where('creator_id', $user->id)
                ->where('status', 'waiting')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $deleted
                    ? "✅ Обычное лобби #{$id} удалено."
                    : '❌ Лобби не найдено или уже недоступно.',
            ]);

            return true;
        }

        if (str_starts_with($data, 'host_cancel_delete_lobby_')) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '↩️ Удаление обычного лобби отменено.',
            ]);
            return true;
        }

        // Премьерное лобби: запрос на удаление
        if (str_starts_with($data, 'premier_delete_')) {
            $id = substr($data, strlen('premier_delete_'));

            $premier = DB::table('premier_lobbies')
                ->where('id', $id)
                ->where('creator_id', $user->id)
                ->where('status', 'published')
                ->first();

            if (!$premier) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Премьерное лобби не найдено или уже недоступно.',
                ]);
                return true;
            }

            $this->askConfirmation(
                $telegram,
                $chatId,
                "Удалить премьерное лобби #{$id}?\nОно исчезнет из поиска.",
                'premier_confirm_delete_' . $id,
                'premier_cancel_delete_' . $id
            );

            return true;
        }

        // Подтверждение удаления премьерного лобби
        if (str_starts_with($data, 'premier_confirm_delete_')) {
            $id = substr($data, strlen('premier_confirm_delete_'));

            $deleted = DB::table('premier_lobbies')
                ->where('id', $id)
                ->where('creator_id', $user->id)
                ->where('status', 'published')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $deleted
                    ? "✅ Премьерное лобби #{$id} отменено и больше не отображается в поиске."
                    : '❌ Премьерное лобби не найдено или уже недоступно.',
            ]);

            return true;
        }

        if (str_starts_with($data, 'premier_cancel_delete_')) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '↩️ Удаление премьерного лобби отменено.',
            ]);
            return true;
        }

        return true;
    }

    private function askConfirmation(
        $telegram,
        $chatId,
        string $text,
        string $confirmData,
        string $cancelData
    ): void {
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "⚠️ {$text}",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Да, удалить',
                            'callback_data' => $confirmData,
                        ],
                        [
                            'text' => '↩️ Отмена',
                            'callback_data' => $cancelData,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
