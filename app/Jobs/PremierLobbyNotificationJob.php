<?php

namespace App\Jobs;

use App\Models\LobbyApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class PremierLobbyNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Типы уведомлений:
     * - application_received
     * - application_approved
     * - application_rejected
     * - published
     *
     * Payload:
     * - application_id
     * - premier_lobby_id
     * - chat_ids
     */
    public function __construct(
        public string $type,
        public array $payload = []
    ) {
        $this->onQueue('telegram');
    }

    public function handle(): void
    {
        if ($this->type === 'published') {
            $this->sendPublishedAnnouncement();

            return;
        }

        if (!in_array($this->type, [
            'application_received',
            'application_approved',
            'application_rejected',
        ], true)) {
            Log::warning('Unknown premier lobby notification type', [
                'type' => $this->type,
            ]);

            return;
        }

        $application = LobbyApplication::with([
            'premierLobby',
            'user',
            'premierLobby.creator',
        ])->find($this->payload['application_id'] ?? null);

        if (!$application || !$application->premierLobby) {
            Log::warning('Premier lobby application not found', [
                'application_id' => $this->payload['application_id'] ?? null,
            ]);

            return;
        }

        $lobby = $application->premierLobby;
        $applicant = $application->user;
        $creator = $lobby->creator;

        if ($this->type === 'application_received') {
            if (!$creator || !$applicant) {
                return;
            }

            $applicantName = $applicant->username
                ? '@' . $applicant->username
                : ($applicant->first_name ?: 'Игрок');

            $answers = $application->answers ?? [];

            if (is_string($answers)) {
                $decoded = json_decode($answers, true);
                $answers = is_array($decoded) ? $decoded : [];
            }

            $answersText = '';

            foreach ($answers as $question => $answer) {
                if (is_array($answer) || is_object($answer)) {
                    $answer = json_encode(
                        $answer,
                        JSON_UNESCAPED_UNICODE
                    );
                }

                $answersText .= "\n• "
                    . $question
                    . ': '
                    . (string) $answer;
            }

            Telegram::bot()->sendMessage([
                'chat_id' => $creator->telegram_id,
                'text' =>
                    "📝 Новая заявка на премьерное лобби\n\n"
                    . "🎬 {$lobby->title}\n"
                    . "👤 Игрок: {$applicantName}\n"
                    . "🗓 Начало: "
                    . Carbon::parse($lobby->starts_at)->format('d.m.Y H:i')
                    . ($answersText
                        ? "\n\nАнкета:" . $answersText
                        : ''),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        [
                            'text' => '✅ Принять',
                            'callback_data' =>
                                'premier_app_approve_' . $application->id,
                        ],
                        [
                            'text' => '❌ Отклонить',
                            'callback_data' =>
                                'premier_app_reject_' . $application->id,
                        ],
                    ]],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return;
        }

        if (in_array($this->type, [
            'application_approved',
            'application_rejected',
        ], true)) {
            if (!$applicant) {
                return;
            }

            $approved = $this->type === 'application_approved';

            Telegram::bot()->sendMessage([
                'chat_id' => $applicant->telegram_id,
                'text' =>
                    ($approved
                        ? '✅ Ваша заявка одобрена.'
                        : '❌ Ваша заявка отклонена.')
                    . "\n\n🎬 Премьерное лобби: {$lobby->title}"
                    . "\n🗓 Начало: "
                    . Carbon::parse($lobby->starts_at)->format('d.m.Y H:i'),
            ]);
        }
    }

    /**
     * Рассылка анонса опубликованного премьерного лобби.
     *
     * Ожидает premier_lobby_id и массив chat_ids в payload.
     */
    private function sendPublishedAnnouncement(): void
    {
        $lobby = DB::table('premier_lobbies')
            ->where('id', $this->payload['premier_lobby_id'] ?? 0)
            ->where('status', 'published')
            ->first();

        if (!$lobby) {
            Log::warning('Published premier lobby not found', [
                'premier_lobby_id' =>
                    $this->payload['premier_lobby_id'] ?? null,
            ]);

            return;
        }

        $chatIds = $this->payload['chat_ids'] ?? [];

        if (!is_array($chatIds) || empty($chatIds)) {
            return;
        }

        $startTime = Carbon::parse($lobby->starts_at)
            ->format('d.m.Y H:i');

        $message =
            "🎬 Премьерное лобби\n\n"
            . "{$lobby->title}\n\n"
            . ($lobby->description ?: 'Описание не указано')
            . "\n\n🗓 Начало: {$startTime}"
            . "\n\nНажмите кнопку ниже, чтобы подать заявку.";

        foreach (array_unique($chatIds) as $chatId) {
            try {
                Telegram::bot()->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                [
                                    'text' => '📝 Подать заявку',
                                    'callback_data' =>
                                    'premier_apply_' . $lobby->id,
                                ],
                            ],
                        ],
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            } catch (\Throwable $e) {
                Log::warning(
                    'Premier lobby announcement failed',
                    [
                        'premier_lobby_id' => $lobby->id,
                        'chat_id' => $chatId,
                        'message' => $e->getMessage(),
                    ]
                );
            }
        }
    }
}
