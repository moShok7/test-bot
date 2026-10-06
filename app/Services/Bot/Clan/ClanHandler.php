<?php

namespace App\Services\Bot\Clan;

use App\Models\BotSession;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\ClanMember;
use App\Models\TelegramUser;
use App\Services\Bot\Clan\ClanService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;
use Throwable;

class ClanHandler
{
    /**
     * Главный чат проекта.
     *
     * /mclan доступен только здесь.
     */
    private const MAIN_CHAT_ID = -1004344778682;

    private const STEP_CLAN_NAME = 'clan_name';
    private const STEP_CLAN_CHAT = 'clan_chat';

    /**
     * Обработка обычных сообщений.
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
            |
            | ВАЖНО:
            | /ms теперь доступен В ЛЮБОМ ЧАТЕ,
            | где присутствует бот.
            |
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
            | TelegramUser
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
            | BotSession
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
            | Чат клана
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
                    'text' =>
                        "❌ <b>Ошибка ClanHandler</b>\n\n" .
                        "<b>Сообщение:</b>\n" .
                        $this->escapeHtml($e->getMessage()) .
                        "\n\n" .
                        "<b>Файл:</b>\n" .
                        $this->escapeHtml(basename($e->getFile())) .
                        "\n" .
                        "<b>Строка:</b> " .
                        $e->getLine(),
                    'parse_mode' => 'HTML',
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
    public function handleDeepLink(
        $message,
        Api $telegram
    ): bool {
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

            $service = app(ClanService::class);

            $invite = $service->getValidInvite($token);

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
            */

            $memberCount = ClanMember::query()
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
                : 'не указан';

            /*
            |--------------------------------------------------------------------------
            | Ссылка на чат
            |--------------------------------------------------------------------------
            */

            $chatLink = $this->getClanChatLink($clan);

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

            if ($chatLink) {
                $inviteText .=
                    "💬 <b>Чат клана:</b>\n" .
                    $this->escapeHtml($chatLink) .
                    "\n\n";
            }

            $inviteText .=
                "Нажмите кнопку ниже, чтобы вступить в клан.";

            /*
            |--------------------------------------------------------------------------
            | Кнопки
            |--------------------------------------------------------------------------
            */

            $keyboard = [];

            if ($chatLink) {
                $keyboard[] = [
                    [
                        'text' => '💬 Открыть чат',
                        'url' => $chatLink,
                    ],
                ];
            }

            $keyboard[] = [
                [
                    'text' => '➕ Вступить в клан',
                    'callback_data' =>
                        'clan_join_' . $invite->token,
                ],
            ];

            /*
            |--------------------------------------------------------------------------
            | Отправляем
            |--------------------------------------------------------------------------
            */

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' => $inviteText,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
                'reply_markup' => json_encode([
                    'inline_keyboard' => $keyboard,
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
    public function handleCallback(
        $callback,
        Api $telegram
    ): bool {
        try {

            $callbackData = $callback->data ?? '';

            if (!str_starts_with($callbackData, 'clan_')) {
                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Вступление в клан
            |--------------------------------------------------------------------------
            */

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
                    // Игнорируем.
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
     *
     * /mclan только в MAIN_CHAT_ID.
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
        | Проверяем главный чат
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
            ->where(
                'telegram_user_id',
                $telegramUser->id
            )
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
                "Поддерживаются:\n" .
                "• группа\n" .
                "• супергруппа\n" .
                "• канал\n\n" .
                "⚠️ Вы должны быть <b>владельцем</b> этого чата.",
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

        /*
        |--------------------------------------------------------------------------
        | Нормализация
        |--------------------------------------------------------------------------
        */

        $chatId = $this->normalizeChatId($chatInput);

        if ($chatId === null) {

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Не удалось распознать Telegram-чат.\n\n" .
                    "Отправьте:\n" .
                    "• @username\n" .
                    "• ссылку t.me/...\n" .
                    "• ID вида -1001234567890.",
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем чат
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

        if (
            !in_array(
                $chatType,
                [
                    'group',
                    'supergroup',
                    'channel',
                ],
                true
            )
        ) {

            $telegram->sendMessage([
                'chat_id' => $message->chat->id,
                'text' =>
                    "❌ Для клана можно зарегистрировать только:\n\n" .
                    "• группу\n" .
                    "• супергруппу\n" .
                    "• канал.",
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
                    "Убедитесь, что бот находится в чате и имеет необходимый доступ.",
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
        | Проверяем регистрацию чата
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

        /*
        |--------------------------------------------------------------------------
        | Удаляем сессию
        |--------------------------------------------------------------------------
        */

        $session->delete();

        /*
        |--------------------------------------------------------------------------
        | Получаем username бота
        |--------------------------------------------------------------------------
        */

        $botUsername = $this->getBotUsername($telegram);

        $inviteLink =
            'https://t.me/' .
            $botUsername .
            '?start=clan_' .
            $invite->token;

        /*
        |--------------------------------------------------------------------------
        | Название чата
        |--------------------------------------------------------------------------
        */

        $chatName = $chat->title
            ?? (
                $chatUsername
                    ? '@' . ltrim($chatUsername, '@')
                    : (string) $realChatId
            );

        /*
        |--------------------------------------------------------------------------
        | Ответ
        |--------------------------------------------------------------------------
        */

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

                "🔗 <b>Ссылка-приглашение:</b>\n" .
                "<code>" .
                $this->escapeHtml($inviteLink) .
                "</code>\n\n" .

                "Отправьте эту ссылку игрокам.",
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,

            /*
            |--------------------------------------------------------------------------
            | Кнопки после создания
            |--------------------------------------------------------------------------
            */

            'reply_markup' => json_encode([
                'inline_keyboard' => array_values(
                    array_filter([
                        $chatLink
                            ? [
                                [
                                    'text' => '💬 Открыть чат',
                                    'url' => $chatLink,
                                ],
                            ]
                            : null,

                        [
                            [
                                'text' => '➕ Вступить в клан',
                                'url' => $inviteLink,
                            ],
                        ],
                    ])
                ),
            ]),
        ]);
    }

    /**
 * Список кланов.
 *
 * Каждый клан отправляется отдельным сообщением.
 * Благодаря этому кнопки одного клана не смешиваются
 * с кнопками других кланов.
 */
private function showClans(
    $message,
    Api $telegram
): void {

    $chatId = (int) ($message->chat->id ?? 0);

    if ($chatId === 0) {
        return;
    }

    /*
    |--------------------------------------------------------------------------
    | Получаем активные кланы
    |--------------------------------------------------------------------------
    */

    $clans = Clan::query()
        ->where('status', 'active')
        ->get();

    /*
    |--------------------------------------------------------------------------
    | Если кланов нет
    |--------------------------------------------------------------------------
    */

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
    | Username бота
    |--------------------------------------------------------------------------
    */

    $botUsername = $this->getBotUsername($telegram);

    /*
    |--------------------------------------------------------------------------
    | Заголовок реестра
    |--------------------------------------------------------------------------
    */

    $telegram->sendMessage([
        'chat_id' => $chatId,
        'text' =>
            "🏴‍☠️ <b>РЕЕСТР КЛАНОВ</b>\n\n" .
            "Найдено кланов: <b>" .
            $clans->count() .
            "</b>\n\n" .
            "Ниже информация по каждому клану 👇",
        'parse_mode' => 'HTML',
    ]);

    /*
    |--------------------------------------------------------------------------
    | Каждый клан отдельным сообщением
    |--------------------------------------------------------------------------
    */

    foreach ($clans as $clan) {

        /*
        |--------------------------------------------------------------------------
        | Количество участников
        |--------------------------------------------------------------------------
        */

        $memberCount = ClanMember::query()
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
        | Ссылка на чат
        |--------------------------------------------------------------------------
        */

        $chatLink = $this->getClanChatLink($clan);

        /*
        |--------------------------------------------------------------------------
        | Активное приглашение
        |--------------------------------------------------------------------------
        */

        $invite = ClanInvite::query()
            ->where('clan_id', $clan->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function ($query) {
                $query->whereNull('max_uses')
                    ->orWhereColumn('uses', '<', 'max_uses');
            })
            ->latest('id')
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Формируем сообщение клана
        |--------------------------------------------------------------------------
        */

        $clanText =
            "🏴‍☠️ <b>" .
            $this->escapeHtml($clan->name) .
            "</b>\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .

            "👑 <b>Лидер:</b> " .
            $this->escapeHtml($creator) .
            "\n" .

            "👥 <b>Участников:</b> " .
            $memberCount .
            "\n";

        /*
        |--------------------------------------------------------------------------
        | Кнопки
        |--------------------------------------------------------------------------
        */

        $keyboard = [];

        /*
        | Открыть чат
        */

        if ($chatLink) {

            $keyboard[] = [
                [
                    'text' => '💬 Открыть чат',
                    'url' => $chatLink,
                ],
            ];
        }

        /*
        | Вступить
        */

        if ($invite) {

            $joinLink =
                'https://t.me/' .
                $botUsername .
                '?start=clan_' .
                $invite->token;

            $keyboard[] = [
                [
                    'text' => '➕ Вступить в клан',
                    'url' => $joinLink,
                ],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Отправляем отдельное сообщение клана
        |--------------------------------------------------------------------------
        */

        $params = [
            'chat_id' => $chatId,
            'text' => $clanText,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if (!empty($keyboard)) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard,
            ]);
        }

        $telegram->sendMessage($params);
    }
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

        /*
        |--------------------------------------------------------------------------
        | Invite
        |--------------------------------------------------------------------------
        */

        $service = app(ClanService::class);

        $invite = $service->getValidInvite($token);

        if (!$invite) {

            $this->editCallbackMessage(
                $callback,
                $telegram,
                "❌ Это приглашение больше недействительно."
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Clan
        |--------------------------------------------------------------------------
        */

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

        if (
            !$this->isMainChatMember(
                $telegram,
                $telegramId
            )
        ) {

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
        | Проверяем существующий клан
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

            $service->useInvite(
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
        | Новый счётчик
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

            "👥 Участников клана: <b>" .
            $memberCount .
            "</b>\n\n" .

            "Добро пожаловать!"
        );
    }

    /**
     * Проверка участника главного чата.
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
        | Ссылка
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | @username
        |--------------------------------------------------------------------------
        */

        $input = ltrim(
            $input,
            '@'
        );

        /*
        |--------------------------------------------------------------------------
        | Убираем query / fragment
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
        | Username
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
     * Ссылка на Telegram-чат клана.
     */
    private function getClanChatLink($clan): ?string
    {
        if (!empty($clan->chat_link)) {
            return $clan->chat_link;
        }

        if (!empty($clan->chat_username)) {
            return
                'https://t.me/' .
                ltrim($clan->chat_username, '@');
        }

        return null;
    }

    /**
     * Получить username текущего бота.
     *
     * Для твоего бота вернёт:
     *
     * @YkSus_test_bot
     */
    private function getBotUsername(
        Api $telegram
    ): string {

        try {

            $me = $telegram->getMe();

            if (!empty($me->username)) {
                return ltrim(
                    $me->username,
                    '@'
                );
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
        | Резервное значение
        |--------------------------------------------------------------------------
        */

        return 'YkSus_test_bot';
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