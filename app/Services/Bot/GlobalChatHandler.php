<?php

namespace App\Services\Bot;

use App\Jobs\GlobalChatDeliveryJob;
use App\Models\ChatMessage;
use App\Models\ChatMessageDelivery;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class GlobalChatHandler
{
    public function handle($message, Api $telegram): bool
    {
        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        $telegramUserId = $message->from->id ?? null;

        if (!$telegramUserId) {
            return false;
        }

        /*
         * ---------------------------------------------------------
         * Автор
         * ---------------------------------------------------------
         */

        $username = $message->from->username ?? null;

        $firstName = trim(
            (string) ($message->from->first_name ?? '')
        );

        $lastName = trim(
            (string) ($message->from->last_name ?? '')
        );

        /*
         * Если username есть:
         *
         *     @username
         *
         * Если username нет:
         *
         *     Имя Фамилия
         *
         * В обоих случаях автор будет text_mention.
         */

        if ($username !== null && trim($username) !== '') {
            $authorName = '@' . trim($username);
        } else {
            $authorName = trim(
                $firstName . ' ' . $lastName
            );

            if ($authorName === '') {
                $authorName = 'Пользователь';
            }
        }

        /*
         * ---------------------------------------------------------
         * Сохраняем пользователя
         * ---------------------------------------------------------
         */

        $user = TelegramUser::updateOrCreate(
            [
                'telegram_id' => $telegramUserId,
            ],
            [
                'username' => $username !== null
                    ? trim($username)
                    : null,

                'first_name' => $firstName !== ''
                    ? $firstName
                    : 'Пользователь',

                'last_name' => $lastName !== ''
                    ? $lastName
                    : null,
            ]
        );

        /*
         * ---------------------------------------------------------
         * Сохраняем сообщение
         * ---------------------------------------------------------
         */

        $chatMessage = ChatMessage::create([
            'telegram_user_id' => $user->id,
            'message' => $text,
        ]);

        /*
         * ---------------------------------------------------------
         * Custom Reply
         * ---------------------------------------------------------
         */

        $replyToChatMessageId = null;

        $replyText = null;
        $replyAuthorName = null;
        $replyAuthorTelegramId = null;
        $replyAuthorFirstName = null;
        $replyAuthorLastName = null;

        $replyToMessage = $message->reply_to_message ?? null;

        if ($replyToMessage) {
            $replyTelegramMessageId =
                $replyToMessage->message_id ?? null;

            if ($replyTelegramMessageId) {
                /*
                 * Ищем сообщение, на которое пользователь
                 * реально нажал Reply в своём личном чате.
                 */

                $delivery = ChatMessageDelivery::query()
                    ->where(
                        'telegram_user_id',
                        $user->id
                    )
                    ->where(
                        'telegram_message_id',
                        $replyTelegramMessageId
                    )
                    ->first();

                if ($delivery) {
                    $originalChatMessage =
                        ChatMessage::query()
                            ->find($delivery->chat_message_id);

                    if ($originalChatMessage) {
                        $replyToChatMessageId =
                            $originalChatMessage->id;

                        $replyText =
                            $originalChatMessage->message;

                        $replyAuthor =
                            TelegramUser::query()
                                ->find(
                                    $originalChatMessage
                                        ->telegram_user_id
                                );

                        if ($replyAuthor) {
                            $replyAuthorTelegramId =
                                (int) $replyAuthor->telegram_id;

                            $replyAuthorUsername =
                                trim(
                                    (string) (
                                        $replyAuthor->username ?? ''
                                    )
                                );

                            $replyAuthorFirstName =
                                trim(
                                    (string) (
                                        $replyAuthor->first_name ?? ''
                                    )
                                );

                            $replyAuthorLastName =
                                trim(
                                    (string) (
                                        $replyAuthor->last_name ?? ''
                                    )
                                );

                            if ($replyAuthorUsername !== '') {
                                $replyAuthorName =
                                    '@' . $replyAuthorUsername;
                            } else {
                                $replyAuthorName = trim(
                                    $replyAuthorFirstName
                                    . ' '
                                    . $replyAuthorLastName
                                );

                                if ($replyAuthorName === '') {
                                    $replyAuthorName =
                                        'Пользователь';
                                }
                            }
                        }

                        Log::info(
                            'GLOBAL CHAT CUSTOM REPLY FOUND',
                            [
                                'currentTelegramUserId' =>
                                    $telegramUserId,

                                'replyTelegramMessageId' =>
                                    $replyTelegramMessageId,

                                'originalChatMessageId' =>
                                    $replyToChatMessageId,

                                'replyAuthorTelegramId' =>
                                    $replyAuthorTelegramId,
                            ]
                        );
                    }
                }
            }
        }

        /*
         * ---------------------------------------------------------
         * Ищем @username в тексте
         * ---------------------------------------------------------
         */

        $mentionedUsers =
            $this->findMentionedUsers($text);

        /*
         * ---------------------------------------------------------
         * Собираем итоговый текст
         * ---------------------------------------------------------
         */

        $chatText = '';

        $entities = [];

        /*
         * ---------------------------------------------------------
         * Reply block
         * ---------------------------------------------------------
         */

        if (
            $replyToChatMessageId !== null
            && $replyText !== null
        ) {
            $chatText .= '↩️ ';

            if (
                $replyAuthorTelegramId !== null
                && $replyAuthorName !== null
            ) {
                /*
                 * ВАЖНО:
                 *
                 * Здесь используется именно offset автора Reply,
                 * а не $authorOffset текущего сообщения.
                 *
                 * Также передаём данные именно replyAuthor.
                 */

                $replyAuthorOffset =
                    $this->utf16Length($chatText);

                $chatText .= $replyAuthorName;

                $entities[] = $this->makeTextMentionEntity(
                    offset: $replyAuthorOffset,
                    text: $replyAuthorName,
                    telegramUserId: $replyAuthorTelegramId,
                    firstName: $replyAuthorFirstName ?: 'Пользователь',
                    lastName: $replyAuthorLastName
                );
            } else {
                $chatText .= 'Ответ';
            }

            $chatText .= "\n";

            $chatText .= $this->makeQuote(
                $replyText
            );

            $chatText .= "\n\n";
        }

        /*
         * ---------------------------------------------------------
         * Автор текущего сообщения
         * ---------------------------------------------------------
         */

        $authorOffset =
            $this->utf16Length($chatText);

        $chatText .= $authorName;

        /*
         * Автор всегда text_mention.
         */

        $entities[] = $this->makeTextMentionEntity(
            offset: $authorOffset,
            text: $authorName,
            telegramUserId: (int) $telegramUserId,
            firstName: $user->first_name ?: 'Пользователь',
            lastName: $user->last_name
        );

        $chatText .= "\n";

        /*
         * ---------------------------------------------------------
         * Иконка
         * ---------------------------------------------------------
         */

        $chatText .= ($user->chat_icon ?? '🟠') . ': ';

        /*
         * ---------------------------------------------------------
         * Основной текст
         * ---------------------------------------------------------
         */

        $chatText .= $text;

        /*
         * ---------------------------------------------------------
         * @username внутри сообщения
         * ---------------------------------------------------------
         *
         * Telegram сам распознает @username как mention.
         *
         * Здесь мы только проверяем, что такой пользователь
         * существует среди пользователей бота.
         */

        foreach ($mentionedUsers as $mentionedUser) {
            Log::debug(
                'GLOBAL CHAT MENTION FOUND',
                [
                    'username' =>
                        $mentionedUser->username,

                    'telegramId' =>
                        $mentionedUser->telegram_id,
                ]
            );
        }

        /*
         * ---------------------------------------------------------
         * Лог
         * ---------------------------------------------------------
         */

        Log::info(
            'BEFORE GlobalChatDeliveryJob dispatch',
            [
                'chatMessageId' =>
                    $chatMessage->id,

                'authorTelegramId' =>
                    $telegramUserId,

                'authorName' =>
                    $authorName,

                'username' =>
                    $username,

                'replyToChatMessageId' =>
                    $replyToChatMessageId,

                'chatText' =>
                    $chatText,

                'entities' =>
                    $entities,
            ]
        );

        /*
         * ---------------------------------------------------------
         * Job
         * ---------------------------------------------------------
         */

        GlobalChatDeliveryJob::dispatch(
            chatMessageId:
                $chatMessage->id,

            replyToChatMessageId:
                $replyToChatMessageId,

            authorTelegramId:
                (int) $telegramUserId,

            chatText:
                $chatText,

            entities:
                $entities,
        )->onQueue('telegram');

        Log::info(
            'AFTER GlobalChatDeliveryJob dispatch',
            [
                'chatMessageId' =>
                    $chatMessage->id,
            ]
        );

        return true;
    }

    /*
     * -------------------------------------------------------------
     * Поиск @username
     * -------------------------------------------------------------
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

        $usernames = [];

        foreach ($matches[1] as $username) {
            $normalized =
                strtolower(trim($username));

            if ($normalized === '') {
                continue;
            }

            $usernames[$normalized] =
                $username;
        }

        if (empty($usernames)) {
            return collect();
        }

        return TelegramUser::query()
            ->whereNotNull('username')
            ->whereIn(
                DB::raw('LOWER(username)'),
                array_keys($usernames)
            )
            ->get();
    }

    /*
     * -------------------------------------------------------------
     * Entity text_mention
     * -------------------------------------------------------------
     */

    private function makeTextMentionEntity(
        int $offset,
        string $text,
        int $telegramUserId,
        string $firstName,
        ?string $lastName = null
    ): array {
        return [
            'type' => 'text_mention',

            'offset' => $offset,

            'length' => $this->utf16Length($text),

            'user' => [
                'id' => $telegramUserId,
                'is_bot' => false,
                'first_name' => $firstName ?: 'Пользователь',
                'last_name' => $lastName,
            ],
        ];
    }

    /*
     * -------------------------------------------------------------
     * Quote
     * -------------------------------------------------------------
     */

    private function makeQuote(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '└ Сообщение';
        }

        $lines = preg_split(
            "/\r\n|\r|\n/",
            $text
        );

        $quotedLines = [];

        foreach ($lines as $line) {
            $quotedLines[] =
                '└ ' . $line;
        }

        return implode(
            "\n",
            $quotedLines
        );
    }

    /*
     * -------------------------------------------------------------
     * UTF-16
     * -------------------------------------------------------------
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