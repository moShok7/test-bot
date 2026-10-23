
<?php

namespace App\Services\Bot\Lobby;

use App\Models\BotSession;
use App\Models\TelegramUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PremierLobbyHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));
        $telegramId = $message->from->id ?? null;
        $chatId = $message->chat->id ?? $telegramId;

        if (!$telegramId || !$chatId || $text === '') {
            return false;
        }

        $user = TelegramUser::where('telegram_id', $telegramId)->first();

        if (!$user) {
            return false;
        }

        $session = BotSession::where(
            'telegram_user_id',
            $user->id
        )->first();

        /*
        |--------------------------------------------------------------------------
        | Начало создания премьерного лобби
        |--------------------------------------------------------------------------
        */

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
                'text' =>
                    "🎬 Создание премьерного лобби\n\n" .
                    "Отправьте название лобби.\n\n" .
                    "Для отмены нажмите «⬅️ Главное меню».",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Обрабатываем только собственные шаги премьерного лобби
        |--------------------------------------------------------------------------
        */

        if (!$session || !str_starts_with((string) $session->step, 'premier_')) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Отмена
        |--------------------------------------------------------------------------
        */

        if ($text === '⬅️ Главное меню') {
            $session->delete();

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "🎮 Главное меню\n\nВыберите действие:",
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [
                            ['text' => '➕ Создать лобби'],
                            ['text' => '🔍 Найти лобби'],
                        ],
                        [
                            ['text' => '🎮 Моё лобби'],
                            ['text' => '👤 Профиль'],
                        ],
                        [
                            ['text' => '⚙️ Настройки'],
                        ],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        $data = $this->decodeSessionData($session->data ?? null);

        /*
        |--------------------------------------------------------------------------
        | Название
        |--------------------------------------------------------------------------
        */

        if ($session->step === 'premier_title') {
            if (mb_strlen($text) < 3 || mb_strlen($text) > 80) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Название должно содержать от 3 до 80 символов. Попробуйте ещё раз.',
                ]);

                return true;
            }

            $data['title'] = $text;

            $this->saveStep(
                $session,
                'premier_description',
                $data
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "✅ Название сохранено: {$text}\n\n" .
                    "📝 Теперь отправьте описание лобби.\n" .
                    'Максимум 1000 символов. Можно отправить точку, если описание не нужно.',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Описание
        |--------------------------------------------------------------------------
        */

        if ($session->step === 'premier_description') {
            if (mb_strlen($text) > 1000) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Описание не должно превышать 1000 символов. Сократите его и отправьте снова.',
                ]);

                return true;
            }

            $data['description'] = $text === '.' ? '' : $text;

            $this->saveStep(
                $session,
                'premier_datetime',
                $data
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🗓 Укажите дату и время начала в формате:\n" .
                    "ДД.ММ.ГГГГ ЧЧ:ММ\n\n" .
                    "Например: 25.10.2026 19:30\n\n" .
                    'Используется часовой пояс приложения Laravel.',
                'reply_markup' => json_encode([
                    'keyboard' => [
                        [['text' => '⬅️ Главное меню']],
                    ],
                    'resize_keyboard' => true,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Дата и время, затем создание записи
        |--------------------------------------------------------------------------
        */

        if ($session->step === 'premier_datetime') {
            try {
                $startsAt = Carbon::createFromFormat(
                    '!d.m.Y H:i',
                    $text
                );

                if (
                    !$startsAt ||
                    $startsAt->format('d.m.Y H:i') !== $text
                ) {
                    throw new \InvalidArgumentException(
                        'Invalid date format'
                    );
                }
            } catch (\Throwable $e) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "❌ Неверная дата или время.\n\n" .
                        "Используйте формат ДД.ММ.ГГГГ ЧЧ:ММ.\n" .
                        'Например: 25.10.2026 19:30',
                ]);

                return true;
            }

            if ($startsAt->lessThanOrEqualTo(now()->addMinutes(5))) {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '⏱ Начало должно быть минимум через 5 минут. Укажите другую дату и время.',
                ]);

                return true;
            }

            if (empty($data['title'])) {
                $session->delete();

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Не найдено название лобби. Начните создание заново командой «➕ Создать лобби».',
                ]);

                return true;
            }

            try {
                $premierLobbyId = DB::table('premier_lobbies')->insertGetId([
                    'creator_id' => $user->id,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? '',
                    'starts_at' => $startsAt->toDateTimeString(),
                    'status' => 'published',
                    'max_players' => 12,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $session->delete();

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        "✅ Премьерное лобби опубликовано!\n\n" .
                        "🆔 ID: #{$premierLobbyId}\n" .
                        "🎬 Название: {$data['title']}\n" .
                        "📝 Описание: " .
                        (($data['description'] ?? '') !== ''
                            ? $data['description']
                            : 'Не указано') .
                        "\n🗓 Начало: " .
                        $startsAt->format('d.m.Y H:i') .
                        "\n\nПока запись создана в базе данных. " .
                        'Публикацию в общем поиске и приём заявок нужно подключить отдельно.',
                    'reply_markup' => json_encode([
                        'keyboard' => [
                            [['text' => '➕ Создать лобби']],
                            [['text' => '🔍 Найти лобби']],
                            [['text' => '⬅️ Главное меню']],
                        ],
                        'resize_keyboard' => true,
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::error('Premier lobby creation failed', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);

                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Не удалось сохранить премьерное лобби. Проверьте таблицу premier_lobbies и журнал Laravel.',
                ]);

                return true;
            }
        }

        return false;
    }

    private function saveStep(
        BotSession $session,
        string $step,
        array $data
    ): void {
        $session->step = $step;
        $session->data = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
        );
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
