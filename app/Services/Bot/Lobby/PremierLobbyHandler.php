<?php

namespace App\Services\Bot\Lobby;

use App\Models\BotSession;
use App\Models\LobbyApplication;
use App\Models\TelegramUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PremierLobbyHandler
{
    /**
     * Handles the conversation for creating a scheduled premier lobby.
     *
     * Required DB tables:
     * - premier_lobbies
     * - lobby_applications
     *
     * This handler intentionally does not change the existing ordinary-lobby flow.
     */
    public function handle($message, $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));
        $telegramId = $message->from->id ?? null;
        $chatId = $message->chat->id ?? $telegramId;

        if (!$telegramId || !$text) {
            return false;
        }

        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return false;
        }

        if ($text === '🎬 Создать премьерное лобби') {
            BotSession::updateOrCreate(
                ['telegram_user_id' => $user->id],
                [
                    'step' => 'premier_title',
                    'data' => json_encode([], JSON_UNESCAPED_UNICODE),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "🎬 Создание премьерного лобби\n\nОтправьте название лобби.\n\nДля отмены нажмите «⬅️ Главное меню».",
                'reply_markup' => json_encode([
                    'keyboard' => [[['text' => '⬅️ Главное меню']]],
                    'resize_keyboard' => true,
                ]),
            ]);

            return true;
        }

        $session = BotSession::where('telegram_user_id', $user->id)->first();

        if (!$session || !str_starts_with((string) $session->step, 'premier_')) {
            return false;
        }

        if ($text === '⬅️ Главное меню') {
            $session->delete();
            return false;
        }

        $data = $this->decodeSessionData($session->data ?? null);

        switch ($session->step) {
            case 'premier_title':
                if (mb_strlen($text) < 3 || mb_strlen($text) > 80) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Название должно содержать от 3 до 80 символов. Отправьте название ещё раз.',
                    ]);
                    return true;
                }

                $data['title'] = $text;
                $this->saveStep($session, 'premier_description', $data);

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Теперь отправьте описание лобби (до 1000 символов).',
                ]);
                return true;

            case 'premier_description':
                if (mb_strlen($text) > 1000) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Описание не должно превышать 1000 символов. Сократите его и отправьте снова.',
                    ]);
                    return true;
                }

                $data['description'] = $text;
                $this->saveStep($session, 'premier_datetime', $data);

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Укажите дату и время начала в формате:\nДД.ММ.ГГГГ ЧЧ:ММ\n\nПример: 25.10.2026 19:30\n\nИспользуется часовой пояс приложения Laravel.",
                ]);
                return true;

            case 'premier_datetime':
                try {
                    $startsAt = Carbon::createFromFormat('d.m.Y H:i', $text);
                    if (!$startsAt || $startsAt->format('d.m.Y H:i') !== $text) {
                        throw new \InvalidArgumentException();
                    }
                } catch (\Throwable $e) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "Неверный формат даты или времени.\nПример: 25.10.2026 19:30",
                    ]);
                    return true;
                }

                if ($startsAt->lessThanOrEqualTo(now()->addMinutes(5))) {
                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'Начало должно быть минимум через 5 минут. Укажите другую дату и время.',
                    ]);
                    return true;
                }

                $data['starts_at'] = $startsAt->toDateTimeString();

                $premierLobbyId = DB::table('premier_lobbies')->insertGetId([
                    'creator_id' => $user->id,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? '',
                    'starts_at' => $data['starts_at'],
                    'status' => 'published',
                    'max_players' => 12,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $session->delete();

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ Премьерное лобби опубликовано!\n\n"
                        . "🆔 ID: #{$premierLobbyId}\n"
                        . "🎬 Название: {$data['title']}\n"
                        . "📝 Описание: " . ($data['description'] ?: 'Не указано') . "\n"
                        . "🗓 Начало: " . $startsAt->format('d.m.Y H:i') . "\n\n"
                        . "Следующий шаг — подключить публикацию анонса в поиск лобби и обработку заявок.",
                ]);
                return true;
        }

        return false;
    }

    private function saveStep(BotSession $session, string $step, array $data): void
    {
        $session->step = $step;
        $session->data = json_encode($data, JSON_UNESCAPED_UNICODE);
        $session->save();
    }

    private function decodeSessionData(?string $json): array
    {
        if (!$json) {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
