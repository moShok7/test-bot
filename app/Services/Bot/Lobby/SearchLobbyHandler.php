
<?php

namespace App\Services\Bot\Lobby;

use App\Models\BotSession;
use App\Models\TelegramUser;
use App\Models\Lobby;
use App\Models\PremierLobby;

class SearchLobbyHandler
{
    public function handle($message, $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));

        if (
            $text !== '🔍 Найти лобби' &&
            $text !== '🔄 Обновить поиск'
        ) {
            return false;
        }

        $chatId = $message->chat->id ?? null;
        $telegramId = $message->from->id ?? null;

        if (!$telegramId || !$chatId) {
            return false;
        }

        $telegramUser = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$telegramUser) {
            return false;
        }

        // Сбрасываем незавершённую сессию создания лобби.
        BotSession::where(
            'telegram_user_id',
            $telegramUser->id
        )->delete();

        $found = false;

        /*
        |--------------------------------------------------------------------------
        | Обычные лобби
        |--------------------------------------------------------------------------
        */

        $lobbies = Lobby::where('status', 'waiting')
            ->where('creator_id', '!=', $telegramUser->id)
            ->with('creator')
            ->withCount('players')
            ->latest()
            ->limit(10)
            ->get();

        foreach ($lobbies as $lobby) {
            if ($lobby->players_count >= $lobby->max_players) {
                continue;
            }

            if (empty($lobby->game_room_code)) {
                continue;
            }

            $found = true;

            $hostNickname = $lobby->creator?->first_name
                ?: (
                    $lobby->creator?->username
                        ? '@' . $lobby->creator->username
                        : 'Игрок'
                );

            $gameLink = 'https://play.suspects.io/?code='
                . $lobby->game_room_code;

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🎮 Обычное лобби #{$lobby->id}\n"
                    . "👑 Хост: {$hostNickname}\n"
                    . "👥 Игроки: {$lobby->players_count}/{$lobby->max_players}\n"
                    . "🔑 Код: {$lobby->game_room_code}\n"
                    . "⏳ Ожидание игроков",
                'reply_markup' => json_encode([
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
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Премьерные лобби
        |--------------------------------------------------------------------------
        */

        $premierLobbies = PremierLobby::where('status', 'published')
            ->where('creator_id', '!=', $telegramUser->id)
            ->where('starts_at', '>', now())
            ->with([
                'creator',
                'applications' => function ($query) use ($telegramUser) {
                    $query->where(
                        'telegram_user_id',
                        $telegramUser->id
                    );
                },
            ])
            ->orderBy('starts_at')
            ->limit(50)
            ->get();

        $shownPremierCount = 0;

        foreach ($premierLobbies as $lobby) {
            if ($shownPremierCount >= 10) {
                break;
            }

            // Учитываем место организатора в общем лимите.
            if (!$lobby->hasAvailableSlots()) {
                continue;
            }

            $found = true;
            $shownPremierCount++;

            $hostNickname = $lobby->creator?->first_name
                ?: (
                    $lobby->creator?->username
                        ? '@' . $lobby->creator->username
                        : 'Игрок'
                );

            $startsAt = $lobby->starts_at
                ? $lobby->starts_at->format('d.m.Y H:i')
                : 'Время не указано';

            $description = trim((string) $lobby->description);

            if ($description === '') {
                $description = 'Описание не указано.';
            }

            if (mb_strlen($description) > 1500) {
                $description = mb_substr($description, 0, 1500) . '…';
            }

            // Получаем существующую заявку пользователя.
            $application = $lobby->applications->first();

            $keyboard = [];
            $applicationMessage =
                'Нажмите кнопку ниже, чтобы отправить заявку организатору.';

            if (!$application) {
                $keyboard[] = [
                    [
                        'text' => '📝 Подать заявку',
                        'callback_data' => 'premier_apply_' . $lobby->id,
                    ],
                ];
            } else {
                switch ($application->status) {
                    case 'pending':
                        $applicationMessage =
                            '⏳ Ваша заявка ожидает рассмотрения организатором.';
                        break;

                    case 'approved':
                        $applicationMessage =
                            '✅ Ваша заявка одобрена организатором.';
                        break;

                    case 'rejected':
                        $applicationMessage =
                            '❌ Ваша заявка была отклонена организатором.';
                        break;

                    default:
                        $applicationMessage =
                            'Вы уже отправляли заявку на это лобби.';
                        break;
                }
            }

            $payload = [
                'chat_id' => $chatId,
                'text' =>
                    "🎬 ПРЕМЬЕРНОЕ ЛОББИ #{$lobby->id}\n\n"
                    . "🎞 Название: {$lobby->title}\n"
                    . "📝 Описание: {$description}\n"
                    . "👑 Организатор: {$hostNickname}\n"
                    . "🗓 Начало: {$startsAt}\n"
                    . "👥 Максимум участников: {$lobby->max_players}\n\n"
                    . $applicationMessage,
            ];

            // Не передаём пустую inline-клавиатуру.
            if (!empty($keyboard)) {
                $payload['reply_markup'] = json_encode([
                    'inline_keyboard' => $keyboard,
                ], JSON_UNESCAPED_UNICODE);
            }

            $telegram->sendMessage($payload);
        }

        /*
        |--------------------------------------------------------------------------
        | Результат поиска
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $found
                ? "🔍 Поиск завершён.\n\nВыше показаны доступные обычные и премьерные лобби."
                : "😔 Сейчас нет доступных лобби.\n\nВы можете создать своё лобби и ждать игроков.",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '🔄 Обновить поиск']],
                    [['text' => '➕ Создать лобби']],
                    [['text' => '🎮 Моё лобби']],
                    [['text' => '⬅️ Главное меню']],
                ],
                'resize_keyboard' => true,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return true;
    }
}
