<?php

namespace App\Services\Bot;

use App\Jobs\GlobalChatDeliveryJob;
use App\Models\ChatMessage;
use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Api;

class GlobalChatHandler
{
    /**
     * Обрабатывает сообщение глобального чата.
     */
    public function handle($message, Api $telegram): bool
    {
        /*
        |--------------------------------------------------------------------------
        | Текст
        |--------------------------------------------------------------------------
        */

        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Telegram ID автора
        |--------------------------------------------------------------------------
        */

        $telegramUserId = $message->from->id ?? null;

        if (!$telegramUserId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Данные автора
        |--------------------------------------------------------------------------
        */

        $username = $message->from->username ?? null;

        $firstName = $message->from->first_name
            ?? 'Пользователь';

        /*
        |--------------------------------------------------------------------------
        | Создаём / обновляем пользователя
        |--------------------------------------------------------------------------
        */

        $user = TelegramUser::updateOrCreate(
            [
                'telegram_id' => $telegramUserId,
            ],
            [
                'username' => $username,
                'first_name' => $firstName,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Проверяем Reply
        |--------------------------------------------------------------------------
        */

        $replyToTelegramMessageId =
            $message->reply_to_message->message_id ?? null;

        $replyToChatMessage = null;

        if ($replyToTelegramMessageId) {
            $replyDelivery = ChatMessageDelivery::query()
                ->where('telegram_user_id', $user->id)
                ->where(
                    'telegram_message_id',
                    $replyToTelegramMessageId
                )
                ->first();

            if ($replyDelivery) {
                $replyToChatMessage = ChatMessage::find(
                    $replyDelivery->chat_message_id
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Сохраняем сообщение
        |--------------------------------------------------------------------------
        */

        $chatMessage = ChatMessage::create([
            'telegram_user_id' => $user->id,
            'message' => $text,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Находим упомянутых пользователей
        |--------------------------------------------------------------------------
        */

        $mentionedUsers = $this->findMentionedUsers($text);

        /*
        |--------------------------------------------------------------------------
        | Получаем имя автора
        |--------------------------------------------------------------------------
        */

        $authorName = $username
            ? '@' . $username
            : $firstName;

        /*
        |--------------------------------------------------------------------------
        | Формируем сообщение
        |--------------------------------------------------------------------------
        */

        $chatText = '';

        $entities = [];

        /*
        |--------------------------------------------------------------------------
        | Автор
        |--------------------------------------------------------------------------
        */

        $authorStartOffset = $this->utf16Length($chatText);

        $chatText .= $authorName;

        $entities[] = [
            'type' => 'text_mention',
            'offset' => $authorStartOffset,
            'length' => $this->utf16Length($authorName),
            'user' => [
                'id' => (int) $telegramUserId,
            ],
        ];

        /*
        |--------------------------------------------------------------------------
        | Новая строка
        |--------------------------------------------------------------------------
        */

        $chatText .= "\n";

        /*
        |--------------------------------------------------------------------------
        | Иконка + сообщение
        |--------------------------------------------------------------------------
        */

        $chatText .= ($user->chat_icon ?? '🟠') . ': ';

        /*
        |--------------------------------------------------------------------------
        | Позиция начала пользовательского текста
        |--------------------------------------------------------------------------
        */

        $messageTextStartOffset =
            $this->utf16Length($chatText);

        $chatText .= $text;

        /*
        |--------------------------------------------------------------------------
        | Настоящие Telegram text_mention
        |--------------------------------------------------------------------------
        */

        foreach ($mentionedUsers as $mentionedUser) {
            if (
                !$mentionedUser->username ||
                !$mentionedUser->telegram_id
            ) {
                continue;
            }

            preg_match_all(
                '/(?<![a-zA-Z0-9_])@'
                . preg_quote($mentionedUser->username, '/')
                . '(?![a-zA-Z0-9_])/iu',
                $text,
                $matches,
                PREG_OFFSET_CAPTURE
            );

            if (empty($matches[0])) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $matchedText = $match[0];
                $byteOffset = $match[1];

                $textBeforeMention = substr(
                    $text,
                    0,
                    $byteOffset
                );

                $mentionOffset =
                    $messageTextStartOffset
                    + $this->utf16Length($textBeforeMention);

                $mentionLength =
                    $this->utf16Length($matchedText);

                $entities[] = [
                    'type' => 'text_mention',
                    'offset' => $mentionOffset,
                    'length' => $mentionLength,
                    'user' => [
                        'id' => (int) $mentionedUser->telegram_id,
                    ],
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Передаём рассылку в очередь
        |--------------------------------------------------------------------------
        |
        | ВАЖНО:
        | Никакого ручного reply-блока здесь нет.
        |
        | Telegram сам отрисует:
        |
        |   ↩ Shohjahon @moShok7
        |   да
        |
        | если передать reply_parameters при отправке.
        |
        */

       \Log::info('BEFORE GlobalChatDeliveryJob dispatch', [
    'chatMessageId' => $chatMessage->id,
    'authorTelegramId' => $telegramUserId,
]);

GlobalChatDeliveryJob::dispatch(
    chatMessageId: $chatMessage->id,
    replyToChatMessageId: $replyToChatMessage?->id,
    authorTelegramId: (int) $telegramUserId,
    chatText: $chatText,
    entities: $entities,
)->onQueue('telegram');

\Log::info('AFTER GlobalChatDeliveryJob dispatch', [
    'chatMessageId' => $chatMessage->id,
]);

        return true;
    }

    /**
     * Находит пользователей, которых упомянули.
     */
    private function findMentionedUsers(string $text)
    {
        preg_match_all(
            '/(?<![a-zA-Z0-9_])@([a-zA-Z0-9_]{1,32})(?![a-zA-Z0-9_])/u',
            $text,
            $matches
        );

        if (empty($matches[1])) {
            return collect();
        }

        /*
        |--------------------------------------------------------------------------
        | Уникальные usernames
        |--------------------------------------------------------------------------
        */

        $usernames = [];

        foreach ($matches[1] as $username) {
            $usernames[strtolower($username)] = $username;
        }

        /*
        |--------------------------------------------------------------------------
        | Ищем пользователей в БД
        |--------------------------------------------------------------------------
        */

        return TelegramUser::query()
            ->whereNotNull('username')
            ->whereIn(
                DB::raw('LOWER(username)'),
                array_keys($usernames)
            )
            ->get();
    }

    /**
     * Возвращает длину строки в UTF-16 code units.
     *
     * Telegram Bot API использует UTF-16
     * для offset/length MessageEntity.
     */
    private function utf16Length(string $text): int
    {
        $utf16 = mb_convert_encoding(
            $text,
            'UTF-16LE',
            'UTF-8'
        );

        return intdiv(
            strlen($utf16),
            2
        );
    }
}