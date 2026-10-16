<?php

namespace App\Services\Bot\Clan;

use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanMute;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class ClanModerationService
{
    /**
     * Обработка команд:
     *
     * /mt @username 10m
     * /mt @username 1h
     * /mt @username 1d
     *
     * /mr @username
     *
     * Также через reply:
     *
     * /mt 10m
     * /mr
     *
     * ВАЖНО:
     * Команды можно выполнять В ЛЮБОМ ЧАТЕ.
     *
     * Логика:
     *
     * 1. Определяем отправителя команды.
     * 2. Находим его клан по creator_id.
     * 3. Проверяем, что цель является участником этого клана.
     * 4. Telegram-мут выдаётся именно в текущем чате.
     *
     * Текущий чат НЕ обязан быть зарегистрирован как chat_id клана.
     */
    public function handle($message, Api $telegram): bool
    {
        $text = trim($message->text ?? '');

        if ($text === '') {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | /mt
        |--------------------------------------------------------------------------
        */

        if (
            $text === '/mt' ||
            str_starts_with($text, '/mt ') ||
            str_starts_with($text, '/mt@')
        ) {
            $this->muteCommand(
                $message,
                $telegram,
                $text
            );

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | /mr
        |--------------------------------------------------------------------------
        */

        if (
            $text === '/mr' ||
            str_starts_with($text, '/mr ') ||
            str_starts_with($text, '/mr@')
        ) {
            $this->unmuteCommand(
                $message,
                $telegram,
                $text
            );

            return true;
        }

        return false;
    }

    /**
     * Находит клан главы.
     *
     * В отличие от старой версии:
     *
     * НЕ ищем клан по текущему chat_id.
     *
     * Команда может выполняться в любом Telegram-чате.
     */
    private function getClanForLeader(TelegramUser $leader): ?Clan
    {
        return Clan::query()
            ->active()
            ->where('creator_id', $leader->id)
            ->first();
    }

    /**
     * /mt
     */
    private function muteCommand(
        $message,
        Api $telegram,
        string $text
    ): void {
        $chatId = (int) ($message->chat->id ?? 0);

        if ($chatId === 0) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим отправителя команды
        |--------------------------------------------------------------------------
        */

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return;
        }

        $leader = TelegramUser::query()
            ->where('telegram_id', $telegramId)
            ->first();

        if (!$leader) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Пользователь не найден.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим клан главы
        |--------------------------------------------------------------------------
        */

        $clan = $this->getClanForLeader($leader);

        if (!$clan) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Вы не являетесь главой активного клана.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем срок мута
        |--------------------------------------------------------------------------
        */

        $duration = $this->extractMuteDuration(
            $message,
            $text
        );

        if ($duration === null) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Укажите срок мута.\n\n" .
                    "Примеры:\n" .
                    "<code>/mt @username 10m</code>\n" .
                    "<code>/mt @username 1h</code>\n" .
                    "<code>/mt @username 1d</code>\n\n" .
                    "Также можно ответить на сообщение пользователя:\n" .
                    "<code>/mt 10m</code>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим пользователя
        |--------------------------------------------------------------------------
        */

        $targetTelegramId = $this->resolveTargetTelegramId(
            $message,
            $text
        );

        if (!$targetTelegramId) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Не удалось определить пользователя.\n\n" .
                    "Используйте:\n" .
                    "<code>/mt @username 10m</code>\n\n" .
                    "или ответьте на сообщение пользователя:\n" .
                    "<code>/mt 10m</code>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Нельзя мутить себя
        |--------------------------------------------------------------------------
        */

        if ((int) $targetTelegramId === (int) $leader->telegram_id) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Нельзя замутить самого себя.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим TelegramUser цели
        |--------------------------------------------------------------------------
        */

        $targetUser = TelegramUser::query()
            ->where('telegram_id', $targetTelegramId)
            ->first();

        if (!$targetUser) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Этот пользователь ещё не зарегистрирован в боте.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем членство цели в клане главы
        |--------------------------------------------------------------------------
        */

        $isClanMember = ClanMember::query()
            ->where('clan_id', $clan->id)
            ->where('user_id', $targetUser->id)
            ->where('status', 'active')
            ->exists();

        if (!$isClanMember) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Пользователь не является участником вашего клана.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Вычисляем дату окончания
        |--------------------------------------------------------------------------
        */

        $expiresAt = now()->addSeconds(
            $duration['seconds']
        );

        /*
        |--------------------------------------------------------------------------
        | Сохраняем мут в БД
        |--------------------------------------------------------------------------
        |
        | Мут логически принадлежит клану главы.
        |
        | clan_id + user_id
        |
        */

        ClanMute::query()->updateOrCreate(
            [
                'clan_id' => $clan->id,
                'user_id' => $targetUser->id,
            ],
            [
                'muted_by' => $leader->id,
                'expires_at' => $expiresAt,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Telegram-мут
        |--------------------------------------------------------------------------
        |
        | ВАЖНО:
        |
        | Здесь используется $chatId.
        |
        | $chatId = тот чат, где была написана команда.
        |
        | Поэтому команду можно использовать в ЛЮБОМ чате.
        */

        try {
            $telegram->restrictChatMember([
                'chat_id' => $chatId,
                'user_id' => $targetTelegramId,

                'permissions' => json_encode([
                    'can_send_messages' => false,
                    'can_send_audios' => false,
                    'can_send_documents' => false,
                    'can_send_photos' => false,
                    'can_send_videos' => false,
                    'can_send_video_notes' => false,
                    'can_send_voice_notes' => false,
                    'can_send_polls' => false,
                    'can_send_other_messages' => false,
                    'can_add_web_page_previews' => false,
                    'can_change_info' => false,
                    'can_invite_users' => false,
                    'can_pin_messages' => false,
                    'can_manage_topics' => false,
                ]),

                'until_date' => $expiresAt->timestamp,
            ]);
        } catch (Throwable $e) {
            /*
            |--------------------------------------------------------------------------
            | Telegram mute не сработал
            |--------------------------------------------------------------------------
            |
            | Не оставляем в БД мут, если Telegram реально не смог
            | ограничить пользователя.
            */

            ClanMute::query()
                ->where('clan_id', $clan->id)
                ->where('user_id', $targetUser->id)
                ->delete();

            Log::error(
                'Telegram clan mute failed',
                [
                    'clan_id' => $clan->id,
                    'command_chat_id' => $chatId,
                    'clan_chat_id' => $clan->chat_id ?? null,
                    'user_id' => $targetTelegramId,
                    'message' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Не удалось выдать мут в этом чате.\n\n" .
                    "Проверьте, что бот является администратором " .
                    "этого чата и имеет право ограничивать участников.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Успешный ответ
        |--------------------------------------------------------------------------
        */

        $username = $targetUser->username
            ? '@' . ltrim($targetUser->username, '@')
            : ($targetUser->first_name ?? 'пользователь');

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🔇 <b><a href=\"tg://openmessage?user_id={$targetTelegramId}\">" .
                $this->escapeHtml($username) .
                "</a> получил(-а) мут</b>\n\n" .
                "⏱ Срок: <b>{$this->escapeHtml($duration['label'])}</b>\n" .
                "🕐 До: <b>" .
                $expiresAt->format('d.m.Y H:i') .
                "</b>\n\n" .
                "📍 Мут действует только в этом чате.",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * /mr
     */
    private function unmuteCommand(
        $message,
        Api $telegram,
        string $text
    ): void {
        $chatId = (int) ($message->chat->id ?? 0);

        if ($chatId === 0) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим отправителя
        |--------------------------------------------------------------------------
        */

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return;
        }

        $leader = TelegramUser::query()
            ->where('telegram_id', $telegramId)
            ->first();

        if (!$leader) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Пользователь не найден.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим клан главы
        |--------------------------------------------------------------------------
        */

        $clan = $this->getClanForLeader($leader);

        if (!$clan) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Вы не являетесь главой активного клана.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Определяем пользователя
        |--------------------------------------------------------------------------
        */

        $targetTelegramId = $this->resolveTargetTelegramId(
            $message,
            $text
        );

        if (!$targetTelegramId) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Не удалось определить пользователя.\n\n" .
                    "Используйте:\n" .
                    "<code>/mr @username</code>\n\n" .
                    "или ответьте на его сообщение:\n" .
                    "<code>/mr</code>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Нельзя изменить собственный мут
        |--------------------------------------------------------------------------
        */

        if ((int) $targetTelegramId === (int) $leader->telegram_id) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Нельзя изменить собственный мут.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим пользователя
        |--------------------------------------------------------------------------
        */

        $targetUser = TelegramUser::query()
            ->where('telegram_id', $targetTelegramId)
            ->first();

        if (!$targetUser) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Пользователь не найден в базе бота.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем членство в клане
        |--------------------------------------------------------------------------
        */

        $isClanMember = ClanMember::query()
            ->where('clan_id', $clan->id)
            ->where('user_id', $targetUser->id)
            ->where('status', 'active')
            ->exists();

        if (!$isClanMember) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Пользователь не является участником вашего клана.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Находим мут
        |--------------------------------------------------------------------------
        */

        $mute = ClanMute::query()
            ->where('clan_id', $clan->id)
            ->where('user_id', $targetUser->id)
            ->first();

        if (!$mute) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'ℹ️ Пользователь сейчас не находится в муте.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Снимаем Telegram-мут
        |--------------------------------------------------------------------------
        |
        | ВАЖНО:
        | Снимаем ограничение именно в том чате,
        | где была выполнена команда /mr.
        */

        try {
            $telegram->restrictChatMember([
                'chat_id' => $chatId,
                'user_id' => $targetTelegramId,

                'permissions' => json_encode([
                    'can_send_messages' => true,
                    'can_send_audios' => true,
                    'can_send_documents' => true,
                    'can_send_photos' => true,
                    'can_send_videos' => true,
                    'can_send_video_notes' => true,
                    'can_send_voice_notes' => true,
                    'can_send_polls' => true,
                    'can_send_other_messages' => true,
                    'can_add_web_page_previews' => true,
                    'can_change_info' => false,
                    'can_invite_users' => true,
                    'can_pin_messages' => false,
                    'can_manage_topics' => false,
                ]),
            ]);
        } catch (Throwable $e) {
            Log::error(
                'Telegram clan unmute failed',
                [
                    'clan_id' => $clan->id,
                    'command_chat_id' => $chatId,
                    'clan_chat_id' => $clan->chat_id ?? null,
                    'user_id' => $targetTelegramId,
                    'message' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Не удалось снять мут в этом чате.\n\n" .
                    "Проверьте, что бот является администратором " .
                    "этого чата и имеет необходимые права.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Удаляем мут из БД
        |--------------------------------------------------------------------------
        */

        $mute->delete();

        $username = $targetUser->username
            ? '@' . ltrim($targetUser->username, '@')
            : ($targetUser->first_name ?? 'пользователь');

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🔊 <b>Мут снят</b>\n\n" .
                "👤 {$this->escapeHtml($username)}\n" .
                "📍 Ограничение снято в этом чате.",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Определяет пользователя:
     *
     * 1. reply
     * 2. @username
     * 3. Telegram ID
     */
    private function resolveTargetTelegramId(
        $message,
        string $text
    ): ?int {
        /*
        |--------------------------------------------------------------------------
        | Reply
        |--------------------------------------------------------------------------
        */

        $reply = $message->replyToMessage
            ?? $message->reply_to_message
            ?? null;

        if ($reply) {
            $replyUser = $reply->from ?? null;

            if ($replyUser && !empty($replyUser->id)) {
                return (int) $replyUser->id;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Убираем команду
        |--------------------------------------------------------------------------
        */

        $commandText = preg_replace(
            '/^\/(?:mt|mr)(?:@\w+)?\s*/i',
            '',
            trim($text)
        );

        if (!$commandText) {
            return null;
        }

        $parts = preg_split(
            '/\s+/',
            trim($commandText)
        );

        if (!$parts || empty($parts[0])) {
            return null;
        }

        $target = trim($parts[0]);

        /*
        |--------------------------------------------------------------------------
        | Telegram ID
        |--------------------------------------------------------------------------
        */

        if (preg_match('/^\d+$/', $target)) {
            return (int) $target;
        }

        /*
        |--------------------------------------------------------------------------
        | @username
        |--------------------------------------------------------------------------
        */

        if (str_starts_with($target, '@')) {
            $username = ltrim($target, '@');

            $user = TelegramUser::query()
                ->whereRaw(
                    'LOWER(username) = ?',
                    [mb_strtolower($username)]
                )
                ->first();

            return $user
                ? (int) $user->telegram_id
                : null;
        }

        return null;
    }

    /**
     * Разбирает срок мута.
     *
     * Поддерживается:
     *
     * 10m
     * 1h
     * 1d
     *
     * Максимум 30 дней.
     */
    private function extractMuteDuration(
        $message,
        string $text
    ): ?array {
        $reply = $message->replyToMessage
            ?? $message->reply_to_message
            ?? null;

        $commandText = preg_replace(
            '/^\/mt(?:@\w+)?\s*/i',
            '',
            trim($text)
        );

        $commandText = trim($commandText);

        /*
        |--------------------------------------------------------------------------
        | Reply:
        |
        | /mt 10m
        |--------------------------------------------------------------------------
        */

        if ($reply && $commandText !== '') {
            $durationText = $commandText;
        } else {
            /*
            |--------------------------------------------------------------------------
            | /mt @username 10m
            |--------------------------------------------------------------------------
            */

            $parts = preg_split(
                '/\s+/',
                $commandText
            );

            if (!$parts || count($parts) < 2) {
                return null;
            }

            $durationText = trim($parts[1]);
        }

        /*
        |--------------------------------------------------------------------------
        | 10m / 1h / 1d
        |--------------------------------------------------------------------------
        */

        if (
            !preg_match(
                '/^(\d+)(m|h|d)$/i',
                $durationText,
                $matches
            )
        ) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        if ($value <= 0) {
            return null;
        }

        switch ($unit) {
            case 'm':
                $seconds = $value * 60;
                $label = $value . ' мин.';
                break;

            case 'h':
                $seconds = $value * 60 * 60;
                $label = $value . ' ч.';
                break;

            case 'd':
                $seconds = $value * 24 * 60 * 60;
                $label = $value . ' дн.';
                break;

            default:
                return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Максимум 30 дней
        |--------------------------------------------------------------------------
        */

        $maxSeconds = 30 * 24 * 60 * 60;

        if ($seconds > $maxSeconds) {
            return null;
        }

        return [
            'seconds' => $seconds,
            'label' => $label,
        ];
    }

    /**
     * HTML escape.
     */
    private function escapeHtml(?string $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}