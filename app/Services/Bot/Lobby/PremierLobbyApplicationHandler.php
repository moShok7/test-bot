<?php

namespace App\Services\Bot\Lobby;

use App\Jobs\PremierLobbyNotificationJob;
use App\Models\LobbyApplication;
use App\Models\PremierLobby;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;

class PremierLobbyApplicationHandler
{
    public function handleCallback($callback, $telegram): bool
    {
        $data = (string) ($callback->data ?? '');

        if (!preg_match(
            '/^premier_(apply|app_approve|app_reject)_(\d+)$/',
            $data,
            $matches
        )) {
            return false;
        }

        $telegramId = $callback->from->id ?? null;
        $chatId = $callback->message->chat->id ?? $telegramId;

        $user = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$user) {
            $this->answer($telegram, $callback, 'Сначала откройте бота командой /start.');
            return true;
        }

        $action = $matches[1];
        $id = (int) $matches[2];

        try {
            if ($action === 'apply') {
                $this->apply($id, $user, $telegram, $callback);
            } else {
                $this->review(
                    $id,
                    $action === 'app_approve',
                    $user,
                    $telegram,
                    $callback
                );
            }
        } catch (\Throwable $e) {
            report($e);
            $this->answer($telegram, $callback, 'Ошибка обработки. Попробуйте позже.');
        }

        return true;
    }

    private function apply($lobbyId, $user, $telegram, $callback): void
    {
        $lobby = PremierLobby::find($lobbyId);

        if (
            !$lobby ||
            $lobby->status !== 'published' ||
            $lobby->starts_at->isPast()
        ) {
            $this->answer($telegram, $callback, 'Это лобби больше недоступно.');
            return;
        }

        if ((int) $lobby->creator_id === (int) $user->id) {
            $this->answer($telegram, $callback, 'Нельзя подать заявку в собственное лобби.');
            return;
        }

        $application = DB::transaction(function () use ($lobby, $user) {
            // Не разрешаем повторно подавать заявку.
            $existing = LobbyApplication::where(
                'premier_lobby_id',
                $lobby->id
            )->where(
                'telegram_user_id',
                $user->id
            )->lockForUpdate()->first();

            if ($existing) {
                return null;
            }

            $approvedCount = LobbyApplication::where(
                'premier_lobby_id',
                $lobby->id
            )->where('status', 'approved')->count();

            if ($approvedCount >= $lobby->max_players) {
                return false;
            }

            return LobbyApplication::create([
                'premier_lobby_id' => $lobby->id,
                'telegram_user_id' => $user->id,
                'status' => 'pending',
                'answers' => [],
            ]);
        });

        if ($application === null) {
            $this->answer($telegram, $callback, 'Вы уже подавали заявку на это лобби.');
            return;
        }

        if ($application === false) {
            $this->answer($telegram, $callback, 'В лобби больше нет свободных мест.');
            return;
        }

        PremierLobbyNotificationJob::dispatch(
            'application_received',
            ['application_id' => $application->id]
        );

        $this->answer($telegram, $callback, 'Заявка отправлена организатору!');
        $telegram->sendMessage([
            'chat_id' => $callback->message->chat->id,
            'text' => "📝 Ваша заявка отправлена!\n\n🎬 {$lobby->title}",
        ]);
    }

    private function review(
        int $applicationId,
        bool $approve,
        $user,
        $telegram,
        $callback
    ): void {
        $application = LobbyApplication::with('premierLobby')
            ->find($applicationId);

        if (!$application || !$application->premierLobby) {
            $this->answer($telegram, $callback, 'Заявка не найдена.');
            return;
        }

        $lobby = $application->premierLobby;

        if ((int) $lobby->creator_id !== (int) $user->id) {
            $this->answer($telegram, $callback, 'Рассматривать заявки может только организатор.');
            return;
        }

        if ($application->status !== 'pending') {
            $this->answer($telegram, $callback, 'Эта заявка уже рассмотрена.');
            return;
        }

        if ($approve) {
            $approvedCount = LobbyApplication::where(
                'premier_lobby_id',
                $lobby->id
            )->where('status', 'approved')->count();

            if ($approvedCount >= $lobby->max_players) {
                $this->answer($telegram, $callback, 'Свободных мест больше нет.');
                return;
            }
        }

        $application->status = $approve ? 'approved' : 'rejected';
        $application->reviewed_by = $user->id;
        $application->reviewed_at = now();
        $application->save();

        PremierLobbyNotificationJob::dispatch(
            $approve ? 'application_approved' : 'application_rejected',
            ['application_id' => $application->id]
        );

        $this->answer(
            $telegram,
            $callback,
            $approve ? 'Заявка одобрена.' : 'Заявка отклонена.'
        );
    }

    private function answer($telegram, $callback, string $text): void
    {
        try {
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback->id,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            // Telegram может сообщить, что callback уже устарел.
        }
    }
}
