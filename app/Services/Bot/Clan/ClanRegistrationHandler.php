<?php

namespace App\Services\Clan;

use App\Models\BotSession;
use App\Models\Clan;
use App\Models\TelegramUser;
use Telegram\Bot\Api;
use Throwable;

class ClanRegistrationHandler
{
    private const SESSION_TIMEOUT_MINUTES = 5;

    private const STEP_NAME = 'clan_name';

    private const STEP_CHAT = 'clan_chat';

    public function __construct(
        private ClanService $clanService
    ) {
    }

    public function handle($message, Api $telegram): bool
    {
        $text = trim($message->text ?? '');

        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return false;
        }

        $chatId = $message->chat->id ?? $telegramId;

        /*
        |--------------------------------------------------------------------------
        | Получаем пользователя
        |--------------------------------------------------------------------------
        */

        $user = TelegramUser::where(
            'telegram_id',
            $telegramId
        )->first();

        if (!$user) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем текущую сессию
        |--------------------------------------------------------------------------
        */

        $session = BotSession::where(
            'telegram_user_id',
            $user->id
        )->first();

        /*
        |--------------------------------------------------------------------------
        | Удаляем просроченную сессию регистрации клана
        |--------------------------------------------------------------------------
        */

        if (
            $session &&
            in_array(
                $session->step,
                [
                    self::STEP_NAME,
                    self::STEP_CHAT,
                ],
                true
            ) &&
            $session->updated_at &&
            $session->updated_at->lt(
                now()->subMinutes(
                    self::SESSION_TIMEOUT_MINUTES
                )
            )
        ) {
            $session->delete();

            $session = null;
        }

        /*
        |--------------------------------------------------------------------------
        | /mclan
        |--------------------------------------------------------------------------
        */

        if (
            $text === '/mclan' ||
            str_starts_with($text, '/mclan@')
        ) {
            return $this->startRegistration(
                $user,
                $chatId,
                $telegram
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Если нет сессии регистрации клана
        |--------------------------------------------------------------------------
        */

        if (!$session) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Обработка названия клана
        |--------------------------------------------------------------------------
        */

        if ($session->step === self::STEP_NAME) {
            return $this->handleClanName(
                $session,
                $text,
                $chatId,
                $telegram
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Обработка Telegram-чата
        |--------------------------------------------------------------------------
        */

        if ($session->step === self::STEP_CHAT) {
            return $this->handleClanChat(
                $session,
                $user,
                $text,
                $chatId,
                $telegram
            );
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Начало регистрации
    |--------------------------------------------------------------------------
    */

    private function startRegistration(
        TelegramUser $user,
        int|string $chatId,
        Api $telegram
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Проверяем, что пользователь находится в главном чате
        |--------------------------------------------------------------------------
        */

        if (!$this->isUserInMainChat(
            $user->telegram_id,
            $telegram
        )) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Создание клана доступно только участникам главного чата."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем существующий клан
        |--------------------------------------------------------------------------
        */

        if (
            $this->clanService->getClanByCreator(
                $user->telegram_id
            )
        ) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Вы уже создали клан.\n\n" .
                    "Один пользователь может создать только один клан."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Сбрасываем старую сессию
        |--------------------------------------------------------------------------
        */

        BotSession::where(
            'telegram_user_id',
            $user->id
        )->delete();

        /*
        |--------------------------------------------------------------------------
        | Создаём новую сессию
        |--------------------------------------------------------------------------
        */

        BotSession::create([
            'telegram_user_id' => $user->id,

            'step' => self::STEP_NAME,

            'temp_clan_name' => null,

            'temp_clan_chat' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Просим название
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "🏰 Создание клана\n\n" .
                "Введите название вашего клана.\n\n" .
                "Максимум: 100 символов.\n" .
                "⏱ У вас есть 5 минут."
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Название клана
    |--------------------------------------------------------------------------
    */

    private function handleClanName(
        BotSession $session,
        string $text,
        int|string $chatId,
        Api $telegram
    ): bool {
        if ($text === '') {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Название клана не может быть пустым.\n\n" .
                    "Введите название ещё раз."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Ограничиваем длину
        |--------------------------------------------------------------------------
        */

        if (mb_strlen($text) > 100) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Название слишком длинное.\n\n" .
                    "Максимальная длина: 100 символов."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Сохраняем название
        |--------------------------------------------------------------------------
        */

        $session->update([
            'step' => self::STEP_CHAT,

            'temp_clan_name' => $text,

            'temp_clan_chat' => null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Просим Telegram-чат
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "🏰 Название клана: {$text}\n\n" .
                "Теперь отправьте Telegram-чат вашего клана.\n\n" .
                "Можно отправить:\n" .
                "• @username чата\n" .
                "• https://t.me/username\n\n" .
                "⚠️ Вы должны быть владельцем этого Telegram-чата.\n" .
                "Обычный администратор не подходит."
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Telegram-чат
    |--------------------------------------------------------------------------
    */

    private function handleClanChat(
        BotSession $session,
        TelegramUser $user,
        string $text,
        int|string $chatId,
        Api $telegram
    ): bool {
        if ($text === '') {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Отправьте @username или ссылку на Telegram-чат."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Приводим ссылку к @username
        |--------------------------------------------------------------------------
        */

        $chatUsername = $this->extractUsername($text);

        if (!$chatUsername) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Не удалось определить Telegram-чат.\n\n" .
                    "Используйте, например:\n" .
                    "@my_clan_chat\n\n" .
                    "или:\n" .
                    "https://t.me/my_clan_chat"
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем информацию о чате
        |--------------------------------------------------------------------------
        */

        try {
            $chat = $telegram->getChat([
                'chat_id' => $chatUsername,
            ]);
        } catch (Throwable $e) {
            \Log::warning(
                'Clan chat lookup failed',
                [
                    'username' => $chatUsername,
                    'error' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Не удалось найти этот Telegram-чат.\n\n" .
                    "Проверьте:\n" .
                    "• username чата указан правильно;\n" .
                    "• чат существует;\n" .
                    "• бот добавлен в этот чат."
            ]);

            return true;
        }

        $telegramChatId = (int) $chat->id;

        /*
        |--------------------------------------------------------------------------
        | Проверяем тип чата
        |--------------------------------------------------------------------------
        */

        $chatType = $chat->type ?? null;

        if (
            !in_array(
                $chatType,
                [
                    'group',
                    'supergroup',
                ],
                true
            )
        ) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Этот Telegram-чат не подходит для клана.\n\n" .
                    "Нужна группа или супергруппа."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем, не зарегистрирован ли чат
        |--------------------------------------------------------------------------
        */

        if (
            $this->clanService->getClanByChatId(
                $telegramChatId
            )
        ) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Этот Telegram-чат уже зарегистрирован за другим кланом."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем владельца
        |--------------------------------------------------------------------------
        */

        try {
            $member = $telegram->getChatMember([
                'chat_id' => $telegramChatId,

                'user_id' => $user->telegram_id,
            ]);
        } catch (Throwable $e) {
            \Log::warning(
                'Clan owner check failed',
                [
                    'chat_id' => $telegramChatId,
                    'user_id' => $user->telegram_id,
                    'error' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Не удалось проверить права в Telegram-чате.\n\n" .
                    "Убедитесь, что бот добавлен в этот чат."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Только creator = владелец
        |--------------------------------------------------------------------------
        */

        $memberStatus = $member->status ?? null;

        if ($memberStatus !== 'creator') {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Вы не являетесь владельцем этого Telegram-чата.\n\n" .
                    "Для регистрации клана необходимо быть именно владельцем.\n" .
                    "Права администратора недостаточны."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем количество участников
        |--------------------------------------------------------------------------
        */

        $memberCount = 0;

        try {
            $memberCount = (int) $telegram->getChatMemberCount([
                'chat_id' => $telegramChatId,
            ]);
        } catch (Throwable $e) {
            \Log::warning(
                'Clan chat member count failed',
                [
                    'chat_id' => $telegramChatId,
                    'error' => $e->getMessage(),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Username
        |--------------------------------------------------------------------------
        */

        $telegramChatUsername =
            $chat->username
            ? (string) $chat->username
            : null;

        /*
        |--------------------------------------------------------------------------
        | Ссылка на чат
        |--------------------------------------------------------------------------
        */

        $telegramChatLink = null;

        if ($telegramChatUsername) {
            $telegramChatLink =
                'https://t.me/' .
                $telegramChatUsername;
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём клан
        |--------------------------------------------------------------------------
        */

        try {
            $clan = $this->clanService->createClan(
                creatorId: (int) $user->telegram_id,

                creatorUsername: $user->username,

                name: $session->temp_clan_name,

                chatId: $telegramChatId,

                chatUsername: $telegramChatUsername,

                chatLink: $telegramChatLink,

                memberCount: $memberCount,
            );
        } catch (Throwable $e) {
            \Log::error(
                'Clan creation failed',
                [
                    'user_id' => $user->telegram_id,
                    'chat_id' => $telegramChatId,
                    'error' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "❌ Не удалось создать клан.\n\n" .
                    "Попробуйте ещё раз."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём приглашение
        |--------------------------------------------------------------------------
        */

        $invite = $this->clanService->createInvite(
            clan: $clan,

            createdBy: (int) $user->telegram_id,
        );

        /*
        |--------------------------------------------------------------------------
        | Удаляем сессию
        |--------------------------------------------------------------------------
        */

        $session->delete();

        /*
        |--------------------------------------------------------------------------
        | Формируем deep link
        |--------------------------------------------------------------------------
        */

        $botUsername =
            env(
                'TELEGRAM_BOT_USERNAME',
                'YkSUS10_bot'
            );

        $inviteLink =
            "https://t.me/{$botUsername}?start=clan_{$invite->token}";

        /*
        |--------------------------------------------------------------------------
        | Имя создателя
        |--------------------------------------------------------------------------
        */

        $creatorName = 'Игрок';

        if ($user->username) {
            $creatorName = '@' . $user->username;
        } elseif ($user->first_name) {
            $creatorName = $user->first_name;
        }

        /*
        |--------------------------------------------------------------------------
        | Успешная регистрация
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,

            'text' =>
                "🏰 Клан успешно создан!\n\n" .
                "🏰 Название: {$clan->name}\n" .
                "👑 Создатель: {$creatorName}\n" .
                "💬 Telegram-чат: " .
                ($telegramChatLink ?? $telegramChatUsername ?? $telegramChatId) .
                "\n" .
                "👥 В Telegram-чате: {$memberCount}\n" .
                "⚔️ В клане: 1\n\n" .
                "🔗 Ваша ссылка-приглашение:\n" .
                $inviteLink,

            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '📋 Скопировать приглашение',

                            'copy_text' => [
                                'text' => $inviteLink,
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Проверка пользователя в главном чате
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

            $status = $member->status ?? null;

            return in_array(
                $status,
                [
                    'creator',
                    'administrator',
                    'member',
                ],
                true
            );
        } catch (Throwable $e) {
            \Log::warning(
                'Main chat membership check failed',
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
    | Получаем username из @username или t.me/username
    |--------------------------------------------------------------------------
    */

    private function extractUsername(string $text): ?string
    {
        $text = trim($text);

        /*
        | @username
        */

        if (preg_match(
            '/^@([A-Za-z0-9_]{5,32})$/',
            $text,
            $matches
        )) {
            return '@' . $matches[1];
        }

        /*
        | https://t.me/username
        */

        if (preg_match(
            '~^(?:https?://)?t\.me/([A-Za-z0-9_]{5,32})/?$~i',
            $text,
            $matches
        )) {
            return '@' . $matches[1];
        }

        return null;
    }
}