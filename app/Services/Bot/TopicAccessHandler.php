
<?php

namespace App\Services\Bot;

use App\Models\TelegramUser;
use App\Models\TopicAccessTopic;
use App\Models\TopicAccessUser;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class TopicAccessHandler
{
    /**
     * Обработка сообщений и управления доступом к темам.
     */
    public function handle($message, Api $telegram): bool
    {
        $chatId = (int) data_get($message, 'chat.id', 0);
        $chatType = (string) data_get($message, 'chat.type', '');
        $userId = (int) data_get($message, 'from.id', 0);
        $messageId = (int) data_get($message, 'message_id', 0);
        $threadId = $this->getThreadId($message);
        $text = trim((string) data_get($message, 'text', ''));

        // Работаем только в супергруппах.
        if ($chatType !== 'supergroup' || $chatId === 0) {
            return false;
        }

        // Сообщения без пользователя, например системные, пропускаем.
        if ($userId === 0) {
            return false;
        }

        // Команды управления работают только внутри темы.
        if ($threadId === null) {
            return false;
        }

        $normalizedText = mb_strtolower($text, 'UTF-8');

        /*
         * ФЕЙМЫ — показать список.
         * Регистр не имеет значения.
         */
        if ($normalizedText === 'феймы') {
            if (!$this->isChatAdmin($telegram, $chatId, $userId)) {
                // Список доступен участникам группы.
                // Администраторская проверка здесь не требуется.
            }

            $this->showFames(
                $telegram,
                $chatId,
                $threadId
            );

            return true;
        }

        /*
         * ДОБАВИТЬ ФЕЙМА
         * ДОБАВИТЬ ФЕЙМА @username
         * ДОБАВИТЬ ФЕЙМА 123456789
         */
        if (preg_match(
            '/^добавить\s+фейма(?:\s+(.+))?$/iu',
            $text,
            $matches
        )) {
            if (!$this->isChatAdmin($telegram, $chatId, $userId)) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    '❌ Управлять списком феймов могут только администраторы группы.'
                );

                return true;
            }

            $lookup = trim($matches[1] ?? '');

            $target = $this->resolveTarget(
                $message,
                $lookup
            );

            if (!$target) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    "❌ Не удалось найти пользователя.\n\n"
                    . "Ответь на его сообщение или укажи username либо Telegram ID:\n"
                    . "<code>добавить фейма @username</code>\n"
                    . "<code>добавить фейма 123456789</code>"
                );

                return true;
            }

            $targetId = (int) $target['id'];

            if ($targetId === $userId) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    '❌ Нельзя добавить самого себя.'
                );

                return true;
            }

            // Создаём защиту темы только после проверки пользователя.
            $topic = TopicAccessTopic::query()->firstOrCreate([
                'chat_id' => $chatId,
                'message_thread_id' => $threadId,
            ]);

            $existing = TopicAccessUser::query()
                ->where('topic_access_topic_id', $topic->id)
                ->where('telegram_user_id', $targetId)
                ->exists();

            if ($existing) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    'ℹ️ Этот пользователь уже есть в списке феймов.'
                );

                return true;
            }

            TopicAccessUser::query()->create([
                'topic_access_topic_id' => $topic->id,
                'telegram_user_id' => $targetId,
                'username' => $target['username'],
                'first_name' => $target['first_name'],
                'added_by' => $userId,
            ]);

            $this->sendReply(
                $telegram,
                $chatId,
                $threadId,
                '👑 <b>Фейм добавлен!</b>' . "\n\n"
                . 'Пользователь: '
                . $this->formatUser(
                    $target['username'],
                    $target['first_name'],
                    $targetId
                )
            );

            return true;
        }

        /*
         * УДАЛИТЬ ФЕЙМА
         * УДАЛИТЬ ФЕЙМА @username
         * УДАЛИТЬ ФЕЙМА 123456789
         */
        if (preg_match(
            '/^удалить\s+фейма(?:\s+(.+))?$/iu',
            $text,
            $matches
        )) {
            if (!$this->isChatAdmin($telegram, $chatId, $userId)) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    '❌ Управлять списком феймов могут только администраторы группы.'
                );

                return true;
            }

            $lookup = trim($matches[1] ?? '');

            $target = $this->resolveTarget(
                $message,
                $lookup
            );

            if (!$target) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    "❌ Не удалось найти пользователя.\n\n"
                    . "Ответь на его сообщение или укажи username либо Telegram ID."
                );

                return true;
            }

            $topic = TopicAccessTopic::query()
                ->where('chat_id', $chatId)
                ->where('message_thread_id', $threadId)
                ->first();

            if (!$topic) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    'ℹ️ В этой теме пока нет списка феймов.'
                );

                return true;
            }

            $removed = TopicAccessUser::query()
                ->where('topic_access_topic_id', $topic->id)
                ->where('telegram_user_id', $target['id'])
                ->delete();

            if ($removed === 0) {
                $this->sendReply(
                    $telegram,
                    $chatId,
                    $threadId,
                    'ℹ️ Этого пользователя нет в списке феймов.'
                );

                return true;
            }

            $this->sendReply(
                $telegram,
                $chatId,
                $threadId,
                '🗑 <b>Фейм удалён!</b>' . "\n\n"
                . 'Пользователь: '
                . $this->formatUser(
                    $target['username'],
                    $target['first_name'],
                    $target['id']
                )
            );

            return true;
        }

        /*
         * Проверка сообщений в защищённой теме.
         * Не добавленные пользователи не могут писать.
         */
        $topic = TopicAccessTopic::query()
            ->where('chat_id', $chatId)
            ->where('message_thread_id', $threadId)
            ->first();

        if (!$topic) {
            return false;
        }

        // Администраторы группы обходят ограничение.
        if ($this->isChatAdmin($telegram, $chatId, $userId)) {
            return false;
        }

        $allowed = TopicAccessUser::query()
            ->where('topic_access_topic_id', $topic->id)
            ->where('telegram_user_id', $userId)
            ->exists();

        if (!$allowed && $messageId > 0) {
            try {
                $telegram->deleteMessage([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                ]);
            } catch (Throwable $e) {
                Log::warning('TopicAccess: failed to delete message', [
                    'chat_id' => $chatId,
                    'thread_id' => $threadId,
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return !$allowed;
    }

    /**
     * Получение ID темы.
     */
    private function getThreadId($message): ?int
    {
        $threadId = (int) (
            data_get($message, 'message_thread_id')
            ?? data_get($message, 'messageThreadId')
            ?? 0
        );

        return $threadId > 0 ? $threadId : null;
    }

    /**
     * Поиск пользователя:
     * 1. По ответу на сообщение.
     * 2. По Telegram ID.
     * 3. По username из локальной базы.
     */
    private function resolveTarget($message, string $lookup): ?array
    {
        if ($lookup === '') {
            $reply = data_get($message, 'reply_to_message')
                ?? data_get($message, 'replyToMessage');

            $from = data_get($reply, 'from');

            if (!$from) {
                return null;
            }

            if ((bool) (
                data_get($from, 'is_bot')
                ?? data_get($from, 'isBot')
                ?? false
            )) {
                return null;
            }

            $id = (int) data_get($from, 'id', 0);

            if ($id <= 0) {
                return null;
            }

            return [
                'id' => $id,
                'username' => $this->normalizeUsername(
                    data_get($from, 'username')
                ),
                'first_name' => $this->cleanName(
                    data_get($from, 'first_name')
                    ?? data_get($from, 'firstName')
                ),
            ];
        }

        $lookup = trim($lookup);

        // Telegram ID.
        if (preg_match('/^\d+$/', $lookup)) {
            $id = (int) $lookup;

            if ($id <= 0) {
                return null;
            }

            $dbUser = TelegramUser::query()
                ->where('telegram_id', $id)
                ->first();

            return [
                'id' => $id,
                'username' => $this->normalizeUsername(
                    $dbUser->username ?? null
                ),
                'first_name' => $this->cleanName(
                    $dbUser->first_name ?? null
                ),
            ];
        }

        // Username можно разрешить только через локальную базу.
        $username = ltrim($lookup, '@');

        if ($username === '') {
            return null;
        }

        $dbUser = TelegramUser::query()
            ->whereRaw('LOWER(username) = ?', [
                mb_strtolower($username, 'UTF-8'),
            ])
            ->first();

        if (!$dbUser) {
            return null;
        }

        $id = (int) $dbUser->telegram_id;

        if ($id <= 0) {
            return null;
        }

        return [
            'id' => $id,
            'username' => $this->normalizeUsername(
                $dbUser->username
            ),
            'first_name' => $this->cleanName(
                $dbUser->first_name
            ),
        ];
    }

    /**
     * Отображение списка феймов.
     */
    private function showFames(
        Api $telegram,
        int $chatId,
        int $threadId
    ): void {
        $topic = TopicAccessTopic::query()
            ->where('chat_id', $chatId)
            ->where('message_thread_id', $threadId)
            ->first();

        $users = $topic
            ? TopicAccessUser::query()
                ->where('topic_access_topic_id', $topic->id)
                ->orderBy('created_at')
                ->get()
            : collect();

        $text = "👑 <b>ФЕЙМЫ</b>\n\n";

        if ($users->isEmpty()) {
            $text .= "Список пока пуст.\n\n";
            $text .= '👥 <b>Всего:</b> 0';
        } else {
            foreach ($users as $user) {
                $name = $this->formatUser(
                    $user->username,
                    $user->first_name,
                    $user->telegram_user_id
                );

                $text .= "👑 {$name}\n";
            }

            $text .= "\n👥 <b>Всего:</b> " . $users->count();
        }

        $this->sendReply(
            $telegram,
            $chatId,
            $threadId,
            $text
        );
    }

    /**
     * Проверка прав администратора группы.
     */
    private function isChatAdmin(
        Api $telegram,
        int $chatId,
        int $userId
    ): bool {
        try {
            $member = $telegram->getChatMember([
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);

            $status = (string) (
                data_get($member, 'status') ?? ''
            );

            return in_array($status, [
                'creator',
                'administrator',
            ], true);
        } catch (Throwable $e) {
            Log::warning('TopicAccess: admin check failed', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Отправка ответа в ту же тему.
     */
    private function sendReply(
        Api $telegram,
        int $chatId,
        int $threadId,
        string $text
    ): void {
        $telegram->sendMessage([
            'chat_id' => $chatId,
            'message_thread_id' => $threadId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Формирование имени пользователя.
     */
    private function formatUser(
        ?string $username,
        ?string $firstName,
        int $userId
    ): string {
        $username = $this->normalizeUsername($username);
        $firstName = $this->cleanName($firstName);

        if ($username !== null) {
            $name = '@' . $username;
        } elseif ($firstName !== null) {
            $name = $firstName;
        } else {
            $name = 'ID: ' . $userId;
        }

        return $this->escapeHtml($name);
    }

    private function normalizeUsername($username): ?string
    {
        $username = trim((string) $username);
        $username = ltrim($username, '@');

        return $username !== '' ? $username : null;
    }

    private function cleanName($name): ?string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : null;
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars(
            $text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
