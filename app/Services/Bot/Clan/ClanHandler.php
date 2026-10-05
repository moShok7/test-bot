<?php

namespace App\Services\Bot\Clan;

use App\Models\BotSession;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\TelegramUser;
use App\Services\Clan\ClanService;
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
            | Состояние создания клана
            |--------------------------------------------------------------------------
            */

            $telegramUser = TelegramUser::query()
                ->where('telegram_id', $telegramId)
                ->first();

            if (!$telegramUser) {
                return false;
            }

            $session = BotSession::query()
                ->where('telegram_user_id', $telegramUser->id)
                ->first();

            if (!$session) {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Название клана
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
            | Telegram-чат клана
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
     * Deep-link:
     *
     * /start clan_TOKEN
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

            $invite = app(ClanService::class)
                ->getValidInvite($token);

            if (!$invite) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ Приглашение недействительно.\n\n" .
                        "Оно могло быть удалено, отключено или срок его действия истёк.",
                ]);

                return true;
            }

            $clan = $invite->clan;

            if (!$clan || !$clan->isActive()) {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' => '❌ Этот клан больше недоступен.',
                ]);

                return true;
            }

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
            | Количество участников
            |--------------------------------------------------------------------------
            |
            | Только наша БД.
            | Telegram getChatMembersCount() НЕ используется.
            |
            */

            $memberCount = ClanMember::query()
                ->where('clan_id', $clan->id)
                ->where('status', 'active')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Создатель
            |--------------------------------------------------------------------------
            */

            $creator = $clan->creator_username
                ? '@' . ltrim($clan->creator_username, '@')
                : 'не указан';

            /*
            |--------------------------------------------------------------------------
            | Ссылка на чат
            |--------------------------------------------------------------------------
            */

            $chatText = null;

            if ($clan->chat_link) {
                $chatText = $clan->chat_link;
            } elseif ($clan->chat_username) {
                $chatText =
                    'https://t.me/' .
                    ltrim($clan->chat_username, '@');
            }

            /*
            |--------------------------------------------------------------------------
            | Текст
            |--------------------------------------------------------------------------
            */

            $inviteText =
                "🏴‍☠️ <b>Приглашение в клан</b>\n\n" .
                "🏴‍☠️ <b>" .
                $this->escapeHtml($clan->name) .
                "</b>\n\n" .
                "👑 <b>Лидер:</b> " .
                $this->escapeHtml($creator) .
                "\n" .
                "👥 <b>Участников:</b> " .
                $memberCount .
                "\n\n";

            if ($chatText) {
                $inviteText .=
                    "💬 <b>Чат клана:</b>\n" .
                    $this->escapeHtml($chatText) .
                    "\n\n";
            }

            $inviteText .=
                "Нажмите кнопку ниже, чтобы вступить в клан.";

            /*
            |--------------------------------------------------------------------------
            | Кнопка вступления
            |--------------------------------------------------------------------------
            */

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => $inviteText,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '➕ Вступить в клан',
                                'callback_data' =>
                                    'clan_join_' . $invite->token,
                            ],
                        ],
                    ],
                ]),
            ]);

            return true;

        } catch (Throwable $e) {
            Log::error(
                'Clan deep link error',
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
     * Callback клана.
     */
    public function handleCallback($callback, Api $telegram): bool
    {
        try {
            $callbackData = $callback->data ?? '';

            if (!str_starts_with($callbackData, 'clan_')) {
                return false;
            }

            if (str_starts_with($callbackData, 'clan_join_')) {
                $token = substr(
                    $callbackData,
                    strlen('clan_join_')
                );

                $this->joinByInvite(
                    $callback,
                    $telegram,
                    $token
                );

                try {
                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback->id,
                    ]);
                } catch (Throwable $e) {
                    // Игнорируем ошибку callback.
                }

                return true;
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
     * Начало создания клана.
     */
    private function startClanCreation(
        $message,
        Api $telegram
    ): void {
        $telegramId = $message->from->id ?? null;

        if (!$telegramId) {
            return;
        }

        if (!$this->isMainChatMember(
            $telegram,
            $telegramId
        )) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ <b>Регистрация клана недоступна.</b>\n\n" .
                    "Чтобы создать клан, вы должны состоять в главном чате проекта.",
                'parse_mode' => 'HTML',
            ]);

            return;
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
        | Проверяем существующий клан
        |--------------------------------------------------------------------------
        */

        $service = app(ClanService::class);

        $existingClan = $service->getClanByCreator(
            $telegramUser->id
        );

        if ($existingClan) {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Вы уже зарегистрировали клан:\n\n" .
                    "🏴‍☠️ <b>" .
                    $this->escapeHtml($existingClan->name) .
                    "</b>\n\n" .
                    "Один пользователь может создать только один клан.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Создаём сессию
        |--------------------------------------------------------------------------
        */

        BotSession::query()
            ->where('telegram_user_id', $telegramUser->id)
            ->delete();

        BotSession::query()->create([
            'telegram_user_id' => $telegramUser->id,
            'step' => self::STEP_CLAN_NAME,
        ]);

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' =>
                "🏴‍☠️ <b>Создание клана</b>\n\n" .
                "Шаг 1 из 2\n\n" .
                "Введите <b>название вашего клана</b>.\n\n" .
                "Максимум: 100 символов.",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Название клана.
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
                    "Максимум: 100 символов.",
            ]);

            return;
        }

        $session->update([
            'step' => self::STEP_CLAN_CHAT,
            'temp_clan_name' => $name,
        ]);

        $telegram->sendMessage([
            'chat_id' => $message->chat->id,
            'text' =>
                "🏴‍☠️ Название клана: <b>" .
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
     * Регистрация Telegram-чата клана.
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
        | Тип чата
        |--------------------------------------------------------------------------
        */

        $chatType = $chat->type ?? null;

       if (!in_array(
    $chatType,
    ['group', 'supergroup', 'channel'],
    true
)) {
    $telegram->sendMessage([
        'chat_id' => $message->chat->id,
        'text' =>
            "❌ Для клана можно зарегистрировать только группу, супергруппу или канал Telegram.",
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
                    "Убедитесь, что бот находится в чате.",
            ]);

            return;
        }

        if ($status !== 'creator') {
            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Вы не являетесь <b>владельцем</b> этого Telegram-чата.\n\n" .
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
                    "🏴‍☠️ Клан: <b>" .
                    $this->escapeHtml($existingClan->name) .
                    "</b>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Данные чата
        |--------------------------------------------------------------------------
        */

        $chatUsername = $chat->username ?? null;

        $chatLink = $chatUsername
            ? 'https://t.me/' . ltrim($chatUsername, '@')
            : null;

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

        $session->delete();

        /*
        |--------------------------------------------------------------------------
        | Username бота
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

        $inviteLink =
            'https://t.me/' .
            $botUsername .
            '?start=clan_' .
            $invite->token;

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
                "🏴‍☠️ <b>" .
                $this->escapeHtml($clan->name) .
                "</b>\n" .
                "💬 Чат: <b>" .
                $this->escapeHtml($chatName) .
                "</b>\n" .
                "👥 В клане: <b>1</b>\n\n" .
                "👑 Вы являетесь главой клана.\n\n" .
                "🔗 <b>Ваша ссылка-приглашение:</b>\n" .
                "<a href=\"" .
                $this->escapeHtml($inviteLink) .
                "\">👉 Нажать для перехода</a>\n\n" .
                "Отправьте эту ссылку игрокам, чтобы они могли вступить в клан.",
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * Список кланов.
     *
     * Количество участников Telegram-чата НЕ запрашивается.
     */
    private function showClans(
        $message,
        Api $telegram
    ): void {
        $chatId = (int) ($message->chat->id ?? 0);

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
            ->get();

        if ($clans->isEmpty()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🏴‍☠️ <b>РЕЕСТР КЛАНОВ</b>\n\n" .
                    "Пока зарегистрированных кланов нет.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Заголовок
        |--------------------------------------------------------------------------
        */

        $text =
            "🏴‍☠️ <b>РЕЕСТР КЛАНОВ</b>\n" .
            "━━━━━━━━━━━━━━━━━━\n\n";

        $keyboard = [];
        $number = 1;

        foreach ($clans as $clan) {

            /*
            |--------------------------------------------------------------------------
            | Количество участников только из ClanMember
            |--------------------------------------------------------------------------
            */

            $clanMembers = ClanMember::query()
                ->where('clan_id', $clan->id)
                ->where('status', 'active')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Лидер
            |--------------------------------------------------------------------------
            */

            $creator = $clan->creator_username
                ? '@' . ltrim($clan->creator_username, '@')
                : 'Неизвестен';

            /*
            |--------------------------------------------------------------------------
            | Информация о клане
            |--------------------------------------------------------------------------
            */

            $text .=
                "🏴‍☠️ <b>{$number}. " .
                $this->escapeHtml($clan->name) .
                "</b>\n" .
                "👑 <b>Лидер:</b> " .
                $this->escapeHtml($creator) .
                "\n" .
                "👥 <b>Участников:</b> " .
                $clanMembers .
                "\n\n";

            /*
            |--------------------------------------------------------------------------
            | Ссылка на Telegram-чат
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
            | Получаем активное приглашение
            |--------------------------------------------------------------------------
            */

            $invite = ClanInvite::query()
                ->where('clan_id', $clan->id)
                ->where('is_active', true)
                ->latest('id')
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Кнопки текущего клана
            |--------------------------------------------------------------------------
            */

            $buttons = [];

            if ($chatLink) {
                $buttons[] = [
                    'text' => '💬 Открыть чат',
                    'url' => $chatLink,
                ];
            }

            if ($invite) {
                $joinLink =
                    'https://t.me/YkSUS10_bot?start=clan_' .
                    $invite->token;

                $buttons[] = [
                    'text' => '➕ Вступить в клан',
                    'url' => $joinLink,
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Добавляем кнопки именно под этим кланом
            |--------------------------------------------------------------------------
            */

            if (!empty($buttons)) {
                $keyboard[] = $buttons;
            }

            $number++;
        }

        /*
        |--------------------------------------------------------------------------
        | Нижняя часть
        |--------------------------------------------------------------------------
        */

        $clanCount = $clans->count();

        $clanWord = match (true) {
            $clanCount % 10 === 1 &&
            $clanCount % 100 !== 11 => 'клан',

            $clanCount % 10 >= 2 &&
            $clanCount % 10 <= 4 &&
            (
                $clanCount % 100 < 10 ||
                $clanCount % 100 >= 20
            ) => 'клана',

            default => 'кланов',
        };

        $text .=
            "━━━━━━━━━━━━━━━━━━\n" .
            "📋 <b>Всего:</b> {$clanCount} {$clanWord}";

        /*
        |--------------------------------------------------------------------------
        | Отправляем
        |--------------------------------------------------------------------------
        */

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard,
            ]),
        ]);
    }

    /**
     * Вступление по приглашению.
     */
    private function joinByInvite(
        $callback,
        Api $telegram,
        string $token
    ): void {
        $telegramId = $callback->from->id ?? null;

        if (!$telegramId) {
            return;
        }

        $invite = app(ClanService::class)
            ->getValidInvite($token);

        if (!$invite) {
            $this->editCallbackMessage(
                $callback,
                $telegram,
                "❌ Это приглашение больше недействительно."
            );

            return;
        }

        $clan = $invite->clan;

        if (!$clan || !$clan->isActive()) {
            $this->editCallbackMessage(
                $callback,
                $telegram,
                "❌ Этот клан больше недоступен."
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Проверяем главный чат
        |--------------------------------------------------------------------------
        */

        if (!$this->isMainChatMember(
            $telegram,
            $telegramId
        )) {
            $telegram->sendMessage([
                'chat_id' => $callback->message->chat->id,
                'text' =>
                    "❌ <b>Вступление недоступно.</b>\n\n" .
                    "Чтобы вступить в клан, вы должны состоять в главном чате проекта.",
                'parse_mode' => 'HTML',
            ]);

            return;
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
            $from = $callback->from;

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
        | Проверяем существующий клан пользователя
        |--------------------------------------------------------------------------
        */

        $alreadyMember = ClanMember::query()
            ->where('user_id', $telegramUser->id)
            ->where('status', 'active')
            ->first();

        if ($alreadyMember) {

            if ($alreadyMember->clan_id === $clan->id) {
                $this->editCallbackMessage(
                    $callback,
                    $telegram,
                    "🏴‍☠️ Вы уже состоите в клане <b>" .
                    $this->escapeHtml($clan->name) .
                    "</b>."
                );

                return;
            }

            $otherClan = $alreadyMember->clan;

            $this->editCallbackMessage(
                $callback,
                $telegram,
                "❌ Вы уже состоите в другом клане:\n\n" .
                "🏴‍☠️ <b>" .
                $this->escapeHtml(
                    $otherClan?->name ?? 'Неизвестный клан'
                ) .
                "</b>"
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Используем приглашение
        |--------------------------------------------------------------------------
        */

        try {
            app(ClanService::class)->useInvite(
                invite: $invite,
                userId: $telegramUser->id,
            );

        } catch (RuntimeException $e) {
            $this->editCallbackMessage(
                $callback,
                $telegram,
                "❌ " .
                $this->escapeHtml($e->getMessage())
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Новый счётчик из БД
        |--------------------------------------------------------------------------
        */

        $memberCount = ClanMember::query()
            ->where('clan_id', $clan->id)
            ->where('status', 'active')
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Результат
        |--------------------------------------------------------------------------
        */

        $this->editCallbackMessage(
            $callback,
            $telegram,
            "🎉 <b>Вы вступили в клан!</b>\n\n" .
            "🏴‍☠️ <b>" .
            $this->escapeHtml($clan->name) .
            "</b>\n\n" .
            "👥 Участников клана: <b>{$memberCount}</b>\n\n" .
            "Добро пожаловать!"
        );
    }

    /**
     * Проверяет участника главного чата.
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
     * Нормализация Telegram chat ID / username.
     */
    private function normalizeChatId(
        string $input
    ): string|int|null {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $input)) {
            return (int) $input;
        }

        $input = preg_replace(
            '#^https?://t\.me/#i',
            '',
            $input
        );

        $input = preg_replace(
            '#^t\.me/#i',
            '',
            $input
        );

        $input = ltrim($input, '@');

        $input = preg_replace(
            '/[?#].*$/',
            '',
            $input
        );

        if ($input === '') {
            return null;
        }

        if (preg_match(
            '/^[A-Za-z0-9_]{5,}$/',
            $input
        )) {
            return '@' . $input;
        }

        return null;
    }

    /**
     * Редактирование callback-сообщения.
     */
    private function editCallbackMessage(
        $callback,
        Api $telegram,
        string $text
    ): void {
        try {
            $telegram->editMessageText([
                'chat_id' =>
                    $callback->message->chat->id,
                'message_id' =>
                    $callback->message->message_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [],
                ]),
            ]);

        } catch (Throwable $e) {
            try {
                $telegram->sendMessage([
                    'chat_id' =>
                        $callback->message->chat->id,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]);
            } catch (Throwable $sendException) {
                Log::warning(
                    'Could not send clan callback result',
                    [
                        'message' =>
                            $sendException->getMessage(),
                    ]
                );
            }
        }
    }

    /**
     * Экранирование HTML.
     */
    private function escapeHtml(
        ?string $value
    ): string {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}