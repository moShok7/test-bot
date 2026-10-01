<?php

namespace App\Services\Clan;

use App\Models\TelegramUser;
use Telegram\Bot\Api;
use Throwable;

class ClanInviteHandler
{
    public function __construct(
        private ClanService $clanService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Deep Link
    |--------------------------------------------------------------------------
    */

    public function handleDeepLink(
        $message,
        Api $telegram
    ): bool {
        $text = trim($message->text ?? '');

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $chatId = $message->chat->id ?? $telegramId;

        if (!preg_match(
            '/^\/start\s+clan_([A-Za-z0-9]+)$/',
            $text,
            $matches
        )) {
            return false;
        }

        $token = $matches[1];

        $invite = $this->clanService->getValidInvite(
            $token
        );

        if (!$invite) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Приглашение недействительно.\n\n" .
                    "Возможно, оно уже истекло или было отключено."
            ]);

            return true;
        }

        $clan = $invite->clan;

        if (!$clan || !$clan->isActive()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Этот клан сейчас недоступен."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем пользователя в главном чате
        |--------------------------------------------------------------------------
        */

        if (!$this->isUserInMainChat(
            $telegramId,
            $telegram
        )) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Вступить в клан могут только участники главного чата.\n\n" .
                    "Сначала вступите в главный чат, затем снова перейдите по приглашению."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Уже состоит в этом клане?
        |--------------------------------------------------------------------------
        */

        if (
            $this->clanService->isMember(
                $clan->id,
                $telegramId
            )
        ) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "⚔️ Вы уже состоите в этом клане.\n\n" .
                    "🏰 {$clan->name}"
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Показываем информацию
        |--------------------------------------------------------------------------
        */

        $creator = $clan->creator_username
            ? '@' . ltrim(
                $clan->creator_username,
                '@'
            )
            : 'Игрок';

        $chatLink = $clan->chat_link;

        $text =
            "🏰 {$clan->name}\n\n" .
            "👑 Создатель: {$creator}\n" .
            "👥 В Telegram-чате: {$clan->member_count}\n" .
            "⚔️ В клане: " .
            $clan->activeMembersCount() .
            "\n\n";

        if ($chatLink) {
            $text .=
                "💬 Telegram-чат:\n" .
                $chatLink .
                "\n\n";
        }

        $text .=
            "Вы хотите вступить в этот клан?";

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' => $text,

            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '⚔️ Вступить',

                            'callback_data' =>
                                'clan_join_' .
                                $invite->token,
                        ],
                    ],
                ],
            ]),
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Callback
    |--------------------------------------------------------------------------
    */

    public function handleCallback(
        $callback,
        Api $telegram
    ): bool {
        $callbackData = $callback->data ?? '';

        if (!str_starts_with(
            $callbackData,
            'clan_join_'
        )) {
            return false;
        }

        $token = str_replace(
            'clan_join_',
            '',
            $callbackData
        );

        $telegramId =
            $callback->from->id ??
            null;

        if (!$telegramId) {
            return true;
        }

        $message = $callback->message;

        $chatId =
            $message->chat->id ??
            $telegramId;

        /*
        |--------------------------------------------------------------------------
        | Проверяем приглашение
        |--------------------------------------------------------------------------
        */

        $invite = $this->clanService->getValidInvite(
            $token
        );

        if (!$invite) {
            $this->answerCallback(
                $telegram,
                $callback->id,
                'Приглашение недействительно.'
            );

            return true;
        }

        $clan = $invite->clan;

        /*
        |--------------------------------------------------------------------------
        | Главный чат
        |--------------------------------------------------------------------------
        */

        if (!$this->isUserInMainChat(
            $telegramId,
            $telegram
        )) {
            $this->answerCallback(
                $telegram,
                $callback->id,
                'Сначала вступите в главный чат.'
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Вступление в клан доступно только участникам главного чата."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Вступаем
        |--------------------------------------------------------------------------
        */

        try {
            $this->clanService->useInvite(
                $invite,
                $telegramId
            );
        } catch (Throwable $e) {
            \Log::error(
                'Clan invite usage failed',
                [
                    'token' => $token,
                    'user_id' => $telegramId,
                    'error' => $e->getMessage(),
                ]
            );

            $this->answerCallback(
                $telegram,
                $callback->id,
                $e->getMessage()
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Callback notification
        |--------------------------------------------------------------------------
        */

        $this->answerCallback(
            $telegram,
            $callback->id,
            'Вы вступили в клан!'
        );

        /*
        |--------------------------------------------------------------------------
        | Сообщение
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "⚔️ Вы вступили в клан!\n\n" .
                "🏰 {$clan->name}\n" .
                "👥 Участников: " .
                $clan->activeMembersCount(),
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Главный чат
    |--------------------------------------------------------------------------
    */

    private function isUserInMainChat(
        int $telegramId,
        Api $telegram
    ): bool {
        try {
            $member = $telegram->getChatMember([
                'chat_id' =>
                    $this->clanService->getMainChatId(),

                'user_id' => $telegramId,
            ]);

            return in_array(
                $member->status ?? null,
                [
                    'creator',
                    'administrator',
                    'member',
                ],
                true
            );
        } catch (Throwable $e) {
            \Log::warning(
                'Clan invite main chat check failed',
                [
                    'user_id' => $telegramId,
                    'error' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Callback answer
    |--------------------------------------------------------------------------
    */

    private function answerCallback(
        Api $telegram,
        string $callbackId,
        string $text
    ): void {
        try {
            $telegram->answerCallbackQuery([
                'callback_query_id' => $callbackId,

                'text' => $text,
            ]);
        } catch (Throwable $e) {
            // Игнорируем ошибку callback.
        }
    }
}