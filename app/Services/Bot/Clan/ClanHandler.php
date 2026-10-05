<?php

namespace App\Services\Clan;

use App\Models\BotSession;
use App\Models\Clan;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;
use Throwable;

class ClanHandler
{
    private const MAIN_CHAT_ID = -1004344778682;

    private const STEP_CLAN_NAME = 'clan_name';
    private const STEP_CLAN_CHAT = 'clan_chat';

    /**
     * Обрабатывает обычные сообщения.
     */
    public function handle($message, Api $telegram): bool
    {
        try {
            $text = trim($message->text ?? '');

            if ($text === '') {
                return false;
            }

            $telegramId = $message->from->id ?? null;

            if (!$telegramId) {
                return false;
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
                $this->startClanCreation(
                    $message,
                    $telegram
                );

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | /ms
            |--------------------------------------------------------------------------
            */

            if (
                $text === '/ms' ||
                str_starts_with($text, '/ms@')
            ) {
                $this->showClans(
                    $message,
                    $telegram
                );

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | /mt
            |--------------------------------------------------------------------------
            |
            | Передаём moderation-команду дальше.
            |
            */

            if (
                $text === '/mt' ||
                str_starts_with($text, '/mt ') ||
                str_starts_with($text, '/mt@')
            ) {
                return false;
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
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Получаем TelegramUser
            |--------------------------------------------------------------------------
            */

            $telegramUser = TelegramUser::query()
                ->where('telegram_id', $telegramId)
                ->first();

            if (!$telegramUser) {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Получаем BotSession
            |--------------------------------------------------------------------------
            */

            $session = BotSession::query()
                ->where('telegram_user_id', $telegramUser->id)
                ->first();

            if (!$session) {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Ввод названия клана
            |--------------------------------------------------------------------------
            */

            if ($session->step === self::STEP_CLAN_NAME) {
                $this->handleClanName(
                    $message,
                    $telegram,
                    $telegramUser,
                    $session,
                    $text
                );

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Ввод Telegram-чата клана
            |--------------------------------------------------------------------------
            */

            if ($session->step === self::STEP_CLAN_CHAT) {
                $this->handleClanChat(
                    $message,
                    $telegram,
                    $telegramUser,
                    $session,
                    $text
                );

                return true;
            }

            return false;

        } catch (Throwable $e) {
            Log::error(
                'Clan handler error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            try {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id ?? null,
                    'text' => '❌ Произошла ошибка при обработке команды.',
                ]);
            } catch (Throwable $sendException) {
                Log::error(
                    'Clan error message failed',
                    [
                        'message' => $sendException->getMessage(),
                    ]
                );
            }

            return true;
        }
    }

    /**
     * Обработка deep-link:
     *
     * https://t.me/YkSUS10_bot?start=clan_TOKEN
     *
     * Переход по ссылке автоматически добавляет пользователя
     * в соответствующий клан.
     */
    public function handleDeepLink($message, Api $telegram): bool
    {
        try {
            $text = trim($message->text ?? '');

            if (!str_starts_with($text, '/start clan_')) {
                return false;
            }

            $token = trim(
                substr(
                    $text,
                    strlen('/start clan_')
                )
            );

            if ($token === '') {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Получаем приглашение
            |--------------------------------------------------------------------------
            */

            $service = app(ClanService::class);

            $invite = $service->getValidInvite($token);

            if (!$invite) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ <b>Приглашение недействительно.</b>\n\n" .
                        "Оно могло быть удалено, отключено или срок его действия истёк.",
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

            $clan = $invite->clan;

            if (!$clan || !$clan->isActive()) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ <b>Этот клан больше недоступен.</b>",
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Telegram ID
            |--------------------------------------------------------------------------
            */

            $telegramId = $message->from->id ?? null;

            if (!$telegramId) {
                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | TelegramUser
            |--------------------------------------------------------------------------
            */

            $telegramUser = TelegramUser::query()
                ->where('telegram_id', $telegramId)
                ->first();

            if (!$telegramUser) {
                $from = $message->from;

                $telegramUser = TelegramUser::query()->updateOrCreate(
                    [
                        'telegram_id' => $telegramId,
                    ],
                    [
                        'username' => $from->username ?? null,
                        'first_name' => $from->first_name ?? null,
                        'last_name' => $from->last_name ?? null,
                    ]
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Проверяем участника главного чата
            |--------------------------------------------------------------------------
            */

            if (
                !$this->isMainChatMember(
                    $telegram,
                    $telegramId
                )
            ) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ <b>Вступление недоступно.</b>\n\n" .
                        "Чтобы вступить в клан, вы должны состоять " .
                        "в главном чате проекта.",
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Проверяем существующий клан
            |--------------------------------------------------------------------------
            */

            $alreadyMember = \App\Models\ClanMember::query()
                ->where('user_id', $telegramUser->id)
                ->where('status', 'active')
                ->first();

            if ($alreadyMember) {

                /*
                |--------------------------------------------------------------------------
                | Уже в этом же клане
                |--------------------------------------------------------------------------
                */

                if ((int) $alreadyMember->clan_id === (int) $clan->id) {
                    $memberCount = $clan->members()
                        ->where('status', 'active')
                        ->count();

                    $telegram->sendMessage([
                        'chat_id' => $message->chat->id,
                        'text' =>
                            "⚔️ <b>Вы уже состоите в этом клане!</b>\n\n" .
                            "🏰 <b>" .
                            $this->escapeHtml($clan->name) .
                            "</b>\n\n" .
                            "👥 Участников: <b>{$memberCount}</b>",
                        'parse_mode' => 'HTML',
                    ]);

                    return true;
                }

                /*
                |--------------------------------------------------------------------------
                | Уже состоит в другом клане
                |--------------------------------------------------------------------------
                */

                $otherClan = $alreadyMember->clan;

                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ <b>Вы уже состоите в другом клане.</b>\n\n" .
                        "🏰 <b>" .
                        $this->escapeHtml(
                            $otherClan?->name ?? 'Неизвестный клан'
                        ) .
                        "</b>\n\n" .
                        "Сначала необходимо выйти из текущего клана.",
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Используем приглашение
            |--------------------------------------------------------------------------
            */

            try {
                $service->useInvite(
                    invite: $invite,
                    userId: $telegramUser->id,
                );
            } catch (RuntimeException $e) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ " .
                        $this->escapeHtml($e->getMessage()),
                    'parse_mode' => 'HTML',
                ]);

                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | Получаем количество участников
            |--------------------------------------------------------------------------
            */

            $memberCount = $clan->members()
                ->where('status', 'active')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Получаем ссылку на чат
            |--------------------------------------------------------------------------
            */

            $chatLink = null;

            if ($clan->chat_link) {
                $chatLink = $clan->chat_link;
            } elseif ($clan->chat_username) {
                $chatLink =
                    'https://t.me/' .
                    ltrim($clan->chat_username, '@');
            }

            /*
            |--------------------------------------------------------------------------
            | Формируем сообщение
            |--------------------------------------------------------------------------
            */

            $joinText =
                "⚔️ <b>Вы вступили в клан!</b>\n\n" .
                "🏰 <b>" .
                $this->escapeHtml($clan->name) .
                "</b>\n\n" .
                "👥 Участников клана: <b>{$memberCount}</b>\n";

            if ($chatLink) {
                $joinText .=
                    "\n💬 <a href=\"" .
                    $this->escapeHtml($chatLink) .
                    "\">Открыть чат клана</a>\n";
            }

            $joinText .=
                "\n🎉 Добро пожаловать!";

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => $joinText,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return true;

        } catch (Throwable $e) {
            Log::error(
                'Clan deep link error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            try {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id ?? null,
                    'text' =>
                        '❌ Произошла ошибка при обработке приглашения.',
                ]);
            } catch (Throwable $sendException) {
                Log::error(
                    'Clan deep link error message failed',
                    [
                        'message' => $sendException->getMessage(),
                    ]
                );
            }

            return true;
        }
    }

    /**
     * Callback клана.
     *
     * Старые кнопки вступления больше не используются.
     */
    public function handleCallback($callback, Api $telegram): bool
    {
        try {
            $callbackData = $callback->data ?? '';

            if (!str_starts_with($callbackData, 'clan_')) {
                return false;
            }

            return false;

        } catch (Throwable $e) {
            Log::error(
                'Clan callback error',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]
            );

            return true;
        }
    }

    /**
     * Начало регистрации клана.
     */
    private function startClanCreation(
        $message,
        Api $telegram
    ): void {
        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем участника главного чата
        |--------------------------------------------------------------------------
        */

        if (
            !$this->isMainChatMember(
                $telegram,
                $telegramId
            )
        ) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ <b>Регистрация клана недоступна.</b>\n\n" .
                    "Чтобы создать клан, вы должны состоять " .
                    "в главном чате проекта.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | TelegramUser
        |--------------------------------------------------------------------------
        */

        $from = $message->from;

        $telegramUser = TelegramUser::query()->updateOrCreate(
            [
                'telegram_id' => $telegramId,
            ],
            [
                'username' => $from->username ?? null,
                'first_name' => $from->first_name ?? null,
                'last_name' => $from->last_name ?? null,
            ]
        );

        $service = app(ClanService::class);

        /*
        |--------------------------------------------------------------------------
        | Проверяем существующий клан
        |--------------------------------------------------------------------------
        */

        $existingClan = $service->getClanByCreator(
            $telegramUser->id
        );

        if ($existingClan) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Вы уже зарегистрировали клан:\n\n" .
                    "🏰 <b>" .
                    $this->escapeHtml($existingClan->name) .
                    "</b>\n\n" .
                    "Один пользователь может создать только один клан.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Очищаем старую сессию
        |--------------------------------------------------------------------------
        */

        BotSession::query()
            ->where('telegram_user_id', $telegramUser->id)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | Создаём новую сессию
        |--------------------------------------------------------------------------
        */

        BotSession::query()->create([
            'telegram_user_id' => $telegramUser->id,
            'step' => self::STEP_CLAN_NAME,
        ]);

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' =>
                "🏰 <b>Создание клана</b>\n\n" .
                "Шаг 1 из 2\n\n" .
                "Введите <b>название вашего клана</b>.\n\n" .
                "Максимум: 100 символов.",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Получение названия клана.
     */
    private function handleClanName(
        $message,
        Api $telegram,
        TelegramUser $telegramUser,
        BotSession $session,
        string $text
    ): void {
        if (
            $text === '❌ Отмена' ||
            $text === '/cancel'
        ) {
            $session->delete();

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => '❌ Создание клана отменено.',
            ]);

            return;
        }

        if (str_starts_with($text, '/')) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Сейчас необходимо ввести название клана.\n\n" .
                    "Для отмены используйте /cancel.",
            ]);

            return;
        }

        $name = trim($text);

        if ($name === '') {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => '❌ Название клана не может быть пустым.',
            ]);

            return;
        }

        if (mb_strlen($name) > 100) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Название слишком длинное.\n\n" .
                    "Максимум — 100 символов.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Сохраняем название
        |--------------------------------------------------------------------------
        */

        $session->update([
            'step' => self::STEP_CLAN_CHAT,
            'temp_clan_name' => $name,
        ]);

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' =>
                "🏰 Название клана: <b>" .
                $this->escapeHtml($name) .
                "</b>\n\n" .
                "Шаг 2 из 2\n\n" .
                "Теперь отправьте <b>Telegram-чат клана</b>.\n\n" .
                "Можно отправить:\n" .
                "• <code>@username</code>\n" .
                "• <code>-1001234567890</code>\n" .
                "• ссылку <code>https://t.me/username</code>\n\n" .
                "⚠️ Вы должны быть <b>владельцем</b> этого Telegram-чата.",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Получение Telegram-чата и регистрация клана.
     */
    private function handleClanChat(
        $message,
        Api $telegram,
        TelegramUser $telegramUser,
        BotSession $session,
        string $text
    ): void {
        if (
            $text === '❌ Отмена' ||
            $text === '/cancel'
        ) {
            $session->delete();

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => '❌ Создание клана отменено.',
            ]);

            return;
        }

        if (str_starts_with($text, '/')) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Сейчас необходимо указать Telegram-чат клана.\n\n" .
                    "Для отмены используйте /cancel.",
            ]);

            return;
        }

        $chatInput = trim($text);

        if ($chatInput === '') {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => '❌ Telegram-чат не указан.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Нормализуем chat ID
        |--------------------------------------------------------------------------
        */

        $chatId = $this->normalizeChatId($chatInput);

        if ($chatId === null) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Не удалось распознать Telegram-чат.\n\n" .
                    "Отправьте @username, ссылку t.me/... или ID вида -1001234567890.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем информацию о чате
        |--------------------------------------------------------------------------
        */

        try {
            $chat = $telegram->getChat([
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            Log::warning(
                'Clan chat lookup failed',
                [
                    'chat' => $chatInput,
                    'message' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Не удалось найти этот Telegram-чат.\n\n" .
                    "Убедитесь, что:\n" .
                    "• чат существует;\n" .
                    "• бот добавлен в этот чат;\n" .
                    "• ID или username указан правильно.",
            ]);

            return;
        }

        $realChatId = (int) ($chat->id ?? 0);

        if ($realChatId === 0) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => '❌ Telegram не вернул ID чата.',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем тип чата
        |--------------------------------------------------------------------------
        */

        $chatType = $chat->type ?? null;

        if (
            !in_array(
                $chatType,
                ['group', 'supergroup'],
                true
            )
        ) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Для клана можно зарегистрировать только группу " .
                    "или супергруппу Telegram.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем владельца
        |--------------------------------------------------------------------------
        */

        try {
            $member = $telegram->getChatMember([
                'chat_id' => $realChatId,
                'user_id' => $telegramUser->telegram_id,
            ]);

            $status = $member->status ?? null;

        } catch (Throwable $e) {
            Log::warning(
                'Clan owner check failed',
                [
                    'chat_id' => $realChatId,
                    'user_id' => $telegramUser->telegram_id,
                    'message' => $e->getMessage(),
                ]
            );

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Не удалось проверить ваши права в этом Telegram-чате.\n\n" .
                    "Убедитесь, что бот находится в чате и может " .
                    "получать информацию об участниках.",
            ]);

            return;
        }

        if ($status !== 'creator') {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Вы не являетесь <b>владельцем</b> этого Telegram-чата.\n\n" .
                    "Зарегистрировать чат может только OWNER.\n\n" .
                    "Ваш текущий статус: <code>" .
                    $this->escapeHtml((string) $status) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем, не зарегистрирован ли чат
        |--------------------------------------------------------------------------
        */

        $service = app(ClanService::class);

        $existingClan = $service->getClanByChatId(
            $realChatId
        );

        if ($existingClan) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Этот Telegram-чат уже зарегистрирован.\n\n" .
                    "🏰 Клан: <b>" .
                    $this->escapeHtml($existingClan->name) .
                    "</b>\n\n" .
                    "Один Telegram-чат нельзя зарегистрировать " .
                    "в нескольких кланах.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем количество участников
        |--------------------------------------------------------------------------
        */

        $memberCount = 0;

        try {
            $countResponse = $telegram->getChatMembersCount([
                'chat_id' => $realChatId,
            ]);

            $memberCount = (int) $countResponse;

        } catch (Throwable $e) {
            Log::warning(
                'Clan chat member count failed',
                [
                    'chat_id' => $realChatId,
                    'message' => $e->getMessage(),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Данные Telegram-чата
        |--------------------------------------------------------------------------
        */

        $chatUsername = $chat->username ?? null;

        if ($chatUsername) {
            $chatLink =
                'https://t.me/' .
                ltrim($chatUsername, '@');
        } else {
            $chatLink = null;
        }

        $clanName = $session->temp_clan_name;

        if (!$clanName) {
            $session->delete();

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Сессия создания клана повреждена.\n\n" .
                    "Начните регистрацию заново через /mclan.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём клан
        |--------------------------------------------------------------------------
        */

        try {
            $clan = $service->createClan(
                creatorId: $telegramUser->id,
                creatorUsername: $telegramUser->username,
                name: $clanName,
                chatId: $realChatId,
                chatUsername: $chatUsername,
                chatLink: $chatLink,
                memberCount: $memberCount,
            );

        } catch (RuntimeException $e) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ " .
                    $this->escapeHtml($e->getMessage()),
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём приглашение
        |--------------------------------------------------------------------------
        */

        $invite = $service->createInvite(
            clan: $clan,
            createdBy: $telegramUser->id,
        );

        /*
        |--------------------------------------------------------------------------
        | Удаляем session
        |--------------------------------------------------------------------------
        */

        $session->delete();

        /*
        |--------------------------------------------------------------------------
        | Получаем username бота
        |--------------------------------------------------------------------------
        */

        $botUsername = 'YkSUS10_bot';

        try {
            $me = $telegram->getMe();

            if (!empty($me->username)) {
                $botUsername = $me->username;
            }
        } catch (Throwable $e) {
            Log::warning(
                'Could not get bot username',
                [
                    'message' => $e->getMessage(),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём обычную Telegram deep-link ссылку
        |--------------------------------------------------------------------------
        */

        $inviteLink =
            'https://t.me/' .
            $botUsername .
            '?start=clan_' .
            $invite->token;

        /*
        |--------------------------------------------------------------------------
        | Финальное сообщение
        |--------------------------------------------------------------------------
        */

        $chatName = $chat->title
            ?? (
                $chatUsername
                    ? '@' . ltrim($chatUsername, '@')
                    : (string) $realChatId
            );

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' =>
                "🎉 <b>Клан успешно зарегистрирован!</b>\n\n" .
                "🏰 <b>" .
                $this->escapeHtml($clan->name) .
                "</b>\n" .
                "💬 Чат: <b>" .
                $this->escapeHtml($chatName) .
                "</b>\n" .
                "👥 В чате: <b>{$memberCount}</b>\n" .
                "⚔️ В клане: <b>1</b>\n\n" .
                "👑 Вы являетесь главой клана.\n\n" .
                "🔗 <b>Ссылка-приглашение:</b>\n" .
                "<a href=\"" .
                $this->escapeHtml($inviteLink) .
                "\">" .
                $this->escapeHtml($inviteLink) .
                "</a>\n\n" .
                "Отправьте эту ссылку игрокам, чтобы они могли вступить в ваш клан.",
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * Список всех активных кланов.
     */
    private function showClans(
        $message,
        Api $telegram
    ): void {
        $chatId = (int) ($message->chat->id ?? 0);

        /*
        |--------------------------------------------------------------------------
        | /ms только в главном чате
        |--------------------------------------------------------------------------
        */

        if ($chatId !== self::MAIN_CHAT_ID) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Команда <code>/ms</code> доступна только в главном чате.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        $clans = Clan::query()
            ->where('status', 'active')
            ->orderByDesc('member_count')
            ->orderBy('name')
            ->get();

        if ($clans->isEmpty()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🏰 <b>Кланы</b>\n\n" .
                    "Пока зарегистрированных кланов нет.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        $text =
            "🏰 <b>Зарегистрированные кланы</b>\n\n";

        $number = 1;

        foreach ($clans as $clan) {

            $clanMembers = $clan->members()
                ->where('status', 'active')
                ->count();

            $creator = $clan->creator_username
                ? '@' . ltrim($clan->creator_username, '@')
                : 'не указан';

            $text .=
                "<b>{$number}. " .
                $this->escapeHtml($clan->name) .
                "</b>\n" .
                "👑 Глава: " .
                $this->escapeHtml($creator) .
                "\n" .
                "👥 В чате: <b>{$clan->member_count}</b>\n" .
                "⚔️ В клане: <b>{$clanMembers}</b>\n";

            /*
            |--------------------------------------------------------------------------
            | Ссылка на чат
            |--------------------------------------------------------------------------
            */

            if ($clan->chat_link) {

                $chatLink = $clan->chat_link;

                $text .=
                    "💬 <a href=\"" .
                    $this->escapeHtml($chatLink) .
                    "\">Открыть чат клана</a>\n";

            } elseif ($clan->chat_username) {

                $chatLink =
                    'https://t.me/' .
                    ltrim($clan->chat_username, '@');

                $text .=
                    "💬 <a href=\"" .
                    $this->escapeHtml($chatLink) .
                    "\">Открыть чат клана</a>\n";
            }

            $text .= "\n";

            $number++;
        }

        $text .=
            "📊 Всего кланов: <b>{$clans->count()}</b>";

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * Проверяет, состоит ли пользователь в главном чате.
     */
    private function isMainChatMember(
        Api $telegram,
        int $telegramId
    ): bool {
        try {
            $member = $telegram->getChatMember([
                'chat_id' => self::MAIN_CHAT_ID,
                'user_id' => $telegramId,
            ]);

            $status = $member->status ?? null;

            if (
                in_array(
                    $status,
                    [
                        'creator',
                        'administrator',
                        'member',
                    ],
                    true
                )
            ) {
                return true;
            }

            /*
            |--------------------------------------------------------------------------
            | restricted + is_member=true
            |--------------------------------------------------------------------------
            */

            if (
                $status === 'restricted' &&
                ($member->is_member ?? false)
            ) {
                return true;
            }

            return false;

        } catch (Throwable $e) {
            Log::warning(
                'Main chat membership check failed',
                [
                    'user_id' => $telegramId,
                    'chat_id' => self::MAIN_CHAT_ID,
                    'message' => $e->getMessage(),
                ]
            );

            return false;
        }
    }

    /**
     * Приводит введённый Telegram-чат к значению,
     * которое можно передать Bot API.
     */
    private function normalizeChatId(
        string $input
    ): string|int|null {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Числовой ID
        |--------------------------------------------------------------------------
        */

        if (preg_match('/^-?\d+$/', $input)) {
            return (int) $input;
        }

        /*
        |--------------------------------------------------------------------------
        | https://t.me/username
        |--------------------------------------------------------------------------
        */

        $input = preg_replace(
            '#^https?://t\.me/#i',
            '',
            $input
        );

        /*
        |--------------------------------------------------------------------------
        | t.me/username
        |--------------------------------------------------------------------------
        */

        $input = preg_replace(
            '#^t\.me/#i',
            '',
            $input
        );

        /*
        |--------------------------------------------------------------------------
        | @username
        |--------------------------------------------------------------------------
        */

        $input = ltrim($input, '@');

        /*
        |--------------------------------------------------------------------------
        | Убираем параметры ссылки
        |--------------------------------------------------------------------------
        */

        $input = preg_replace(
            '/[?#].*$/',
            '',
            $input
        );

        if ($input === '') {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Username Telegram
        |--------------------------------------------------------------------------
        */

        if (
            preg_match(
                '/^[A-Za-z0-9_]{5,}$/',
                $input
            )
        ) {
            return '@' . $input;
        }

        return null;
    }

    /**
     * Безопасное экранирование HTML.
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