<?php

namespace App\Services\Bot;

use App\Models\TopicAccessTopic;
use App\Models\TopicAccessUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class TopicAccessHandler
{
    /**
     * Обрабатывает команды управления доступом и проверяет сообщения
     * в защищённых темах. Возвращает true, если сообщение обработано.
     */
    public function handle($message, Api $telegram): bool
    {
        $chatId = (int) ($message->chat->id ?? 0);
        $chatType = $message->chat->type ?? null;
        $userId = (int) ($message->from->id ?? 0);
        $messageId = (int) ($message->messageId ?? $message->message_id ?? 0);
        $threadId = $this->getThreadId($message);
        $text = trim((string) ($message->text ?? ''));

        if ($chatId === 0 || $userId === 0 || $chatType !== 'supergroup') {
            return false;
        }

        $command = $this->getCommand($text);

        // Команды управления должны обрабатываться до проверки списка доступа.
        if (in_array($command, ['/topicadd', '/topicremove', '/topiclist'], true)) {
            if ($threadId === null) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    null,
                    '❌ Команду нужно отправить внутри конкретной темы форума.'
                );
                return true;
            }

            if (!$this->isChatAdmin($telegram, $chatId, $userId)) {
                $this->sendReply($telegram, $chatId, $threadId, '❌ Управлять доступом к теме могут только администраторы группы.');
                return true;
            }

            if ($command === '/topicadd') {
                $reply = $message->replyToMessage ?? $message->reply_to_message ?? null;
                $target = $reply->from ?? null;
                $targetId = (int) ($target->id ?? 0);

                if (!$targetId || ($target->isBot ?? $target->is_bot ?? false)) {
                    $this->sendReply($telegram, $chatId, $threadId, '❌ Используй /topicadd ответом на сообщение пользователя.');
                    return true;
                }

                if ($targetId === $userId) {
                    $this->sendReply($telegram, $chatId, $threadId, '❌ Нельзя добавить самого себя этой командой.');
                    return true;
                }

                // Включаем защиту только после корректного /topicadd с ответом на сообщение.
                $topic = TopicAccessTopic::query()->firstOrCreate([
                    'chat_id' => $chatId,
                    'message_thread_id' => $threadId,
                ]);

                TopicAccessUser::query()->firstOrCreate(
                    [
                        'topic_access_topic_id' => $topic->id,
                        'telegram_user_id' => $targetId,
                    ],
                    [
                        'username' => $target->username ?? null,
                        'first_name' => $target->firstName ?? $target->first_name ?? null,
                        'added_by' => $userId,
                    ]
                );

                // Обновляем данные, если пользователь уже был в списке.
                TopicAccessUser::query()
                    ->where('topic_access_topic_id', $topic->id)
                    ->where('telegram_user_id', $targetId)
                    ->update([
                        'username' => $target->username ?? null,
                        'first_name' => $target->firstName ?? $target->first_name ?? null,
                    ]);

                $display = $this->formatUser($targetId, $target->username ?? null, $target->firstName ?? $target->first_name ?? null);
                $this->sendReply($telegram, $chatId, $threadId, "✅ {$display} добавлен(а) в список разрешённых этой темы. Защита темы включена.");
                return true;
            }

            $topic = TopicAccessTopic::query()
                ->where('chat_id', $chatId)
                ->where('message_thread_id', $threadId)
                ->first();

            if (!$topic) {
                $this->sendReply($telegram, $chatId, $threadId, 'ℹ️ Защита этой темы ещё не включена. Ответь командой /topicadd на сообщение пользователя, чтобы включить её.');
                return true;
            }

            if ($command === '/topicremove') {
                $reply = $message->replyToMessage ?? $message->reply_to_message ?? null;
                $target = $reply->from ?? null;
                $targetId = (int) ($target->id ?? 0);

                if (!$targetId) {
                    $this->sendReply($telegram, $chatId, $threadId, '❌ Используй /topicremove ответом на сообщение пользователя из списка.');
                    return true;
                }

                $deleted = TopicAccessUser::query()
                    ->where('topic_access_topic_id', $topic->id)
                    ->where('telegram_user_id', $targetId)
                    ->delete();

                $display = $this->formatUser($targetId, $target->username ?? null, $target->firstName ?? $target->first_name ?? null);
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    $deleted
                        ? "✅ {$display} удалён(а) из списка. Защита темы остаётся включённой."
                        : "ℹ️ {$display} не найден(а) в списке этой темы."
                );
                return true;
            }

            $users = TopicAccessUser::query()
                ->where('topic_access_topic_id', $topic->id)
                ->orderBy('first_name')
                ->orderBy('telegram_user_id')
                ->get();

            if ($users->isEmpty()) {
                $this->sendReply($telegram, $chatId, $threadId, '📋 Список разрешённых пуст. Тема защищена: писать могут только администраторы, пока ты не добавишь пользователей через /topicadd.');
                return true;
            }

            $lines = ['📋 <b>Разрешённые пользователи этой темы:</b>', ''];
            foreach ($users as $user) {
                $lines[] = '• ' . $this->escapeHtml($this->formatUser(
                    (int) $user->telegram_user_id,
                    $user->username,
                    $user->first_name
                ));
            }

            $this->sendReply($telegram, $chatId, $threadId, implode("\n", $lines), 'HTML');
            return true;
        }

        // Не защищённая тема — не вмешиваемся.
        if ($threadId === null) {
            return false;
        }

        $topic = TopicAccessTopic::query()
            ->where('chat_id', $chatId)
            ->where('message_thread_id', $threadId)
            ->first();

        if (!$topic) {
            return false;
        }

        // Разрешённые пользователи проходят без дополнительного запроса к Telegram.
        $allowed = TopicAccessUser::query()
            ->where('topic_access_topic_id', $topic->id)
            ->where('telegram_user_id', $userId)
            ->exists();

        if ($allowed || $this->isChatAdmin($telegram, $chatId, $userId)) {
            return false;
        }

        if ($messageId > 0) {
            try {
                $telegram->deleteMessage([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
            } catch (Throwable $e) {
                Log::warning('Не удалось удалить сообщение в защищённой теме', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'thread_id' => $threadId,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    private function getThreadId($message): ?int
    {
        $id = $message->messageThreadId ?? $message->message_thread_id ?? null;
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function getCommand(string $text): string
    {
        if (!preg_match('/^\/(topicadd|topicremove|topiclist)(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text, $matches)) {
            return '';
        }

        return '/' . strtolower($matches[1]);
    }

    private function isChatAdmin(Api $telegram, int $chatId, int $userId): bool
    {
        try {
            $status = Cache::remember(
                "topic_access_admin_{$chatId}_{$userId}",
                now()->addSeconds(45),
                function () use ($telegram, $chatId, $userId) {
                    $member = $telegram->getChatMember([
                        'chat_id' => $chatId,
                        'user_id' => $userId,
                    ]);

                    return $member->status ?? null;
                }
            );

            return in_array($status, ['creator', 'administrator'], true);
        } catch (Throwable $e) {
            Log::warning('Не удалось проверить права администратора для защиты темы', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function sendReply(Api $telegram, int $chatId, ?int $threadId, string $text, ?string $parseMode = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        $telegram->sendMessage($params);
    }

    private function formatUser(int $id, ?string $username, ?string $firstName): string
    {
        if ($username) {
            return '@' . ltrim($username, '@') . " (ID: {$id})";
        }

        if ($firstName) {
            return "{$firstName} (ID: {$id})";
        }

        return "ID: {$id}";
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
