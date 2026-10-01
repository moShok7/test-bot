<?php

namespace App\Services\Clan;

use App\Models\Clan;
use App\Models\ClanMute;
use App\Models\TelegramUser;
use Telegram\Bot\Api;
use Throwable;

class ClanMuteHandler
{
    public function __construct(
        private ClanService $clanService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Команды /mt и /mr
    |--------------------------------------------------------------------------
    */

    public function handle(
        $message,
        Api $telegram
    ): bool {
        $text = trim($message->text ?? '');

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Определяем команду
        |--------------------------------------------------------------------------
        */

        $command = null;

        if (
            $text === '/mt' ||
            str_starts_with($text, '/mt@')
        ) {
            $command = 'mute';
        }

        if (
            $text === '/mr' ||
            str_starts_with($text, '/mr@')
        ) {
            $command = 'unmute';
        }

        if (!$command) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Клан пользователя
        |--------------------------------------------------------------------------
        */

        $clan = $this->clanService->getClanByCreator(
            $telegramId
        );

        if (!$clan || !$clan->isActive()) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Управлять мутами может только глава зарегистрированного клана."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем, что команда отправлена в Telegram-канале клана
        |--------------------------------------------------------------------------
        */

        $messageChatId =
            (int) ($message->chat->id ?? 0);

        if (
            $messageChatId !==
            (int) $clan->chat_id
        ) {
            $telegram->sendMessage([
                'chat_id' => $messageChatId,

                'text' =>
                    "❌ Эта команда работает только в Telegram-чате вашего клана."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | MUTE
        |--------------------------------------------------------------------------
        */

        if ($command === 'mute') {
            return $this->mute(
                $message,
                $telegram,
                $clan
            );
        }

        /*
        |--------------------------------------------------------------------------
        | UNMUTE
        |--------------------------------------------------------------------------
        */

        return $this->unmute(
            $message,
            $telegram,
            $clan
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MUTE
    |--------------------------------------------------------------------------
    */

    private function mute(
        $message,
        Api $telegram,
        Clan $clan
    ): bool {
        $text = trim($message->text ?? '');

        /*
        |--------------------------------------------------------------------------
        | Получаем аргументы
        |--------------------------------------------------------------------------
        */

        $parts = preg_split(
            '/\s+/',
            $text
        );

        array_shift($parts);

        $targetUsername = null;
        $duration = null;

        /*
        |--------------------------------------------------------------------------
        | Если reply
        |--------------------------------------------------------------------------
        */

        $replyMessage =
            $message->replyToMessage ??
            $message->reply_to_message ??
            null;

        if ($replyMessage) {
            /*
            | /mt 10m
            */

            if (isset($parts[0])) {
                $duration = $parts[0];
            }

            /*
            | Если передан username после reply,
            | игнорируем его.
            */
            $targetUsername = null;
        } else {
            /*
            | /mt @username 10m
            */

            if (isset($parts[0])) {
                $targetUsername = $this->normalizeUsername(
                    $parts[0]
                );
            }

            if (isset($parts[1])) {
                $duration = $parts[1];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем duration
        |--------------------------------------------------------------------------
        */

        if (!$duration) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Укажите время мута.\n\n" .
                    "Примеры:\n" .
                    "/mt @username 10m\n" .
                    "/mt @username 1h\n" .
                    "/mt @username 1d\n\n" .
                    "Или ответьте на сообщение:\n" .
                    "/mt 10m"
            ]);

            return true;
        }

        $seconds = $this->parseDuration(
            $duration
        );

        if ($seconds === null) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Неверное время.\n\n" .
                    "Используйте:\n" .
                    "10m — 10 минут\n" .
                    "1h — 1 час\n" .
                    "1d — 1 день"
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Определяем Telegram ID цели
        |--------------------------------------------------------------------------
        */

        $targetTelegramId = null;

        if ($replyMessage) {
            $targetTelegramId =
                $replyMessage->from->id ??
                null;
        } elseif ($targetUsername) {
            $targetUser = TelegramUser::query()
                ->where(
                    'username',
                    ltrim(
                        $targetUsername,
                        '@'
                    )
                )
                ->first();

            if (!$targetUser) {
                $telegram->sendMessage([
                    'chat_id' =>
                        $message->chat->id,

                    'text' =>
                        "❌ Пользователь {$targetUsername} не найден."
                ]);

                return true;
            }

            $targetTelegramId =
                (int) $targetUser->telegram_id;
        }

        if (!$targetTelegramId) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Не удалось определить пользователя.\n\n" .
                    "Используйте @username или ответьте на сообщение."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Нельзя замутить себя
        |--------------------------------------------------------------------------
        */

        if (
            $targetTelegramId ===
            (int) $message->from->id
        ) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Нельзя замутить самого себя."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем участника клана
        |--------------------------------------------------------------------------
        */

        if (
            !$this->clanService->isMember(
                $clan->id,
                $targetTelegramId
            )
        ) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Этот пользователь не состоит в вашем клане."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем Telegram-права цели
        |--------------------------------------------------------------------------
        */

        try {
            $member =
                $telegram->getChatMember([
                    'chat_id' =>
                        $clan->chat_id,

                    'user_id' =>
                        $targetTelegramId,
                ]);

            $targetStatus =
                $member->status ?? null;

            /*
            | Нельзя мутить владельца/админа.
            */

            if (in_array(
                $targetStatus,
                [
                    'creator',
                    'administrator',
                ],
                true
            )) {
                $telegram->sendMessage([
                    'chat_id' =>
                        $message->chat->id,

                    'text' =>
                        "❌ Нельзя замутить владельца или администратора Telegram-чата."
                ]);

                return true;
            }
        } catch (Throwable $e) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Не удалось проверить пользователя в Telegram."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Время окончания
        |--------------------------------------------------------------------------
        */

        $expiresAt =
            now()->addSeconds(
                $seconds
            );

        /*
        |--------------------------------------------------------------------------
        | Мут в Telegram
        |--------------------------------------------------------------------------
        */

        $chatService =
            new ClanChatService($telegram);

        if (!$chatService->mute(
            $clan->chat_id,
            $targetTelegramId,
            $expiresAt->timestamp
        )) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Telegram не позволил выдать мут.\n\n" .
                    "Проверьте, что бот является администратором чата и имеет право ограничивать участников."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Записываем мут в БД
        |--------------------------------------------------------------------------
        */

        ClanMute::query()->updateOrCreate(
            [
                'clan_id' =>
                    $clan->id,

                'user_id' =>
                    $targetTelegramId,
            ],
            [
                'muted_by' =>
                    (int) $message->from->id,

                'expires_at' =>
                    $expiresAt,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Username для сообщения
        |--------------------------------------------------------------------------
        */

        $displayName =
            $targetUsername ??
            $this->getReplyName(
                $replyMessage
            ) ??
            'пользователь';

        $telegram->sendMessage([
            'chat_id' =>
                $message->chat->id,

            'text' =>
                "🔇 Пользователь {$displayName} получил мут.\n\n" .
                "⏱ До: " .
                $expiresAt->format('d.m.Y H:i'),
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | UNMUTE
    |--------------------------------------------------------------------------
    */

    private function unmute(
        $message,
        Api $telegram,
        Clan $clan
    ): bool {
        $text = trim($message->text ?? '');

        $parts = preg_split(
            '/\s+/',
            $text
        );

        array_shift($parts);

        $targetUsername = null;

        $replyMessage =
            $message->replyToMessage ??
            $message->reply_to_message ??
            null;

        if (!$replyMessage && isset($parts[0])) {
            $targetUsername =
                $this->normalizeUsername(
                    $parts[0]
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Определяем ID
        |--------------------------------------------------------------------------
        */

        $targetTelegramId = null;

        if ($replyMessage) {
            $targetTelegramId =
                $replyMessage->from->id ??
                null;
        } elseif ($targetUsername) {
            $targetUser = TelegramUser::query()
                ->where(
                    'username',
                    ltrim(
                        $targetUsername,
                        '@'
                    )
                )
                ->first();

            if (!$targetUser) {
                $telegram->sendMessage([
                    'chat_id' =>
                        $message->chat->id,

                    'text' =>
                        "❌ Пользователь {$targetUsername} не найден."
                ]);

                return true;
            }

            $targetTelegramId =
                (int) $targetUser->telegram_id;
        }

        if (!$targetTelegramId) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Укажите @username или ответьте на сообщение пользователя."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Снимаем Telegram mute
        |--------------------------------------------------------------------------
        */

        $chatService =
            new ClanChatService($telegram);

        if (!$chatService->unmute(
            $clan->chat_id,
            $targetTelegramId
        )) {
            $telegram->sendMessage([
                'chat_id' =>
                    $message->chat->id,

                'text' =>
                    "❌ Не удалось снять мут в Telegram."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Удаляем мут из БД
        |--------------------------------------------------------------------------
        */

        ClanMute::query()
            ->where(
                'clan_id',
                $clan->id
            )
            ->where(
                'user_id',
                $targetTelegramId
            )
            ->delete();

        $displayName =
            $targetUsername ??
            $this->getReplyName(
                $replyMessage
            ) ??
            'пользователь';

        $telegram->sendMessage([
            'chat_id' =>
                $message->chat->id,

            'text' =>
                "🔊 Мут с пользователя {$displayName} снят."
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Парсер времени
    |--------------------------------------------------------------------------
    */

    private function parseDuration(
        string $duration
    ): ?int {
        $duration = strtolower(
            trim($duration)
        );

        if (!preg_match(
            '/^(\d+)(s|m|h|d|w)$/',
            $duration,
            $matches
        )) {
            return null;
        }

        $value = (int) $matches[1];

        $unit = $matches[2];

        if ($value <= 0) {
            return null;
        }

        return match ($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 60 * 60,
            'd' => $value * 60 * 60 * 24,
            'w' => $value * 60 * 60 * 24 * 7,
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Username
    |--------------------------------------------------------------------------
    */

    private function normalizeUsername(
        string $username
    ): ?string {
        $username = trim($username);

        if (!preg_match(
            '/^@?[A-Za-z0-9_]{5,32}$/',
            $username
        )) {
            return null;
        }

        return '@' . ltrim(
            $username,
            '@'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Имя из reply
    |--------------------------------------------------------------------------
    */

    private function getReplyName(
        $message
    ): ?string {
        if (!$message) {
            return null;
        }

        $user = $message->from ?? null;

        if (!$user) {
            return null;
        }

        if ($user->username) {
            return '@' . $user->username;
        }

        if ($user->first_name) {
            return $user->first_name;
        }

        return null;
    }
}