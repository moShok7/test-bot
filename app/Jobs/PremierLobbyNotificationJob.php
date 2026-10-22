<?php

namespace App\Jobs;

use App\Models\LobbyApplication;
use App\Models\TelegramUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
     * $type:
     * - application_received: notify the lobby creator about a new application
     * - application_approved: notify the applicant that they were accepted
     * - application_rejected: notify the applicant that they were rejected
     * - published: announce a newly published premier lobby to supplied chat IDs
     *
     * $payload can contain:
     * - application_id
     * - premier_lobby_id
     * - chat_ids (for published announcements)
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

        $application = LobbyApplication::with([
            'premierLobby',
            'user',
            'premierLobby.creator',
        ])->find($this->payload['application_id'] ?? null);

        if (!$application || !$application->premierLobby) {
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
            $answersText = '';
            foreach ($answers as $question => $answer) {
                $answersText .= "\n• " . $question . ': ' . $answer;
            }

            Telegram::bot()->sendMessage([
                'chat_id' => $creator->telegram_id,
                'text' => "📝 Новая заявка на премьерное лобби\n\n"
                    . "🎬 {$lobby->title}\n"
                    . "👤 Игрок: {$applicantName}\n"
                    . "🗓 Начало: " . $lobby->starts_at->format('d.m.Y H:i')
                    . ($answersText ? "\n\nАнкета:" . $answersText : ''),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[
                        ['text' => '✅ Принять', 'callback_data' => 'premier_app_approve_' . $application->id],
                        ['text' => '❌ Отклонить', 'callback_data' => 'premier_app_reject_' . $application->id],
                    ]],
                ]),
            ]);
            return;
        }

        if (in_array($this->type, ['application_approved', 'application_rejected'], true)) {
            if (!$applicant) {
                return;
            }

            $approved = $this->type === 'application_approved';
            Telegram::bot()->sendMessage([
                'chat_id' => $applicant->telegram_id,
                'text' => ($approved ? '✅ Ваша заявка одобрена.' : '❌ Ваша заявка отклонена.')
                    . "\n\n🎬 Премьерное лобби: {$lobby->title}"
                    . "\n🗓 Начало: " . $lobby->starts_at->format('d.m.Y H:i'),
            ]);
        }
    }

    private function sendPublishedAnnouncement(): void
    {
        $lobby = \Illuminate\Support\Facades\DB::table('premier_lobbies')
            ->where('id', $this->payload['premier_lobby_id'] ?? 0)
            ->first();

        if (!$lobby) {
            return;
        }

        foreach (($this->payload['chat_ids'] ?? []) as $chatId) {
            Telegram::bot()->sendMessage([
                'chat_id' => $chatId,
                'text' => "🎬 Премьерное лобби\n\n"
                    . "{$lobby->title}\n\n"
                    . ($lobby->description ?: 'Описание не указано')
                    . "\n\n🗓 Начало: " . \Illuminate\Support\Carbon::parse($lobby->starts_at)->format('d.m.Y H:i')
                    . "\n\nЧтобы подать заявку, откройте бота и выберите премьерное лобби.",
            ]);
        }
    }
}
