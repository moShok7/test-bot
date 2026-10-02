<?php

namespace App\Services\Bot\Clan;

use App\Models\BotSession;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Throwable;

class ClanHandler
{
    /**
     * Главный чат системы кланов.
     */
    private const MAIN_CHAT_ID = -1004344778682;

    /**
     * Обработка команд кланов.
     */
    public function handle($message, Api $telegram): bool
    {
        try {
            /*
             * ---------------------------------------------------------
             * CALLBACK
             * ---------------------------------------------------------
             */
            if (isset($message->callback_query)) {
                return $this->handleCallback($message, $telegram);
            }

            $text = trim($message->text ?? '');
            $chatId = (int) ($message->chat->id ?? 0);
            $userId = (int) ($message->from->id ?? 0);

            if ($chatId === 0 || $userId === 0) {
                return false;
            }

            /*
             * ---------------------------------------------------------
             * /start clan_TOKEN
             * ---------------------------------------------------------
             */
            if (
                preg_match(
                    '/^\/start(?:@\w+)?\s+clan_([A-Za-z0-9]+)$/i',
                    $text,
                    $matches
                )
            ) {
                return $this->showClanInvite(
                    $message,
                    $telegram,
                    $matches[1]
                );
            }

            /*
             * ---------------------------------------------------------
             * /mclan
             * ---------------------------------------------------------
             */
            if (preg_match('/^\/mclan(?:@\w+)?$/i', $text)) {
                $this->startClanCreation(
                    $message,
                    $telegram
                );

                return true;
            }

            /*
             * ---------------------------------------------------------
             * /ms
             * ---------------------------------------------------------
             */
            if (preg_match('/^\/ms(?:@\w+)?$/i', $text)) {

                if (!$this->isMainChat($chatId)) {
                    return false;
                }

                $this->showClans(
                    $message,
                    $telegram
                );

                return true;
            }

            /*
             * ---------------------------------------------------------
             * /mt
             *
             * Передаём ClanModerationService.
             * ---------------------------------------------------------
             */
            if (preg_match('/^\/mt(?:@\w+)?(?:\s+.*)?$/i', $text)) {
                return false;
            }

            /*
             * ---------------------------------------------------------
             * /mr
             *
             * Передаём ClanModerationService.
             * ---------------------------------------------------------
             */
            if (preg_match('/^\/mr(?:@\w+)?(?:\s+.*)?$/i', $text)) {
                return false;
            }

            /*
             * ---------------------------------------------------------
             * ПРОДОЛЖЕНИЕ СОЗДАНИЯ КЛАНА
             * ---------------------------------------------------------
             */
            $session = BotSession::query()
                ->where('telegram_user_id', $userId)
                ->first();

            if (!$session) {
                return false;
            }

            if ($session->step === 'clan_name') {
                return $this->handleClanName(
                    $message,
                    $telegram,
                    $session
                );
            }

            if ($session->step === 'clan_chat') {
                return $this->handleClanChat(
                    $message,
                    $telegram,
                    $session
                );
            }

            return false;

        } catch (Throwable $e) {

            Log::error('ClanHandler fatal error', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' =>
                        "❌ <b>Ошибка клановой системы.</b>\n\n" .
                        "<code>" .
                        $this->escapeHtml($e->getMessage()) .
                        "</code>",
                    'parse_mode' => 'HTML',
                ]);
            } catch (Throwable $sendException) {

                Log::error('ClanHandler error response failed', [
                    'error' => $sendException->getMessage(),
                ]);
            }

            return true;
        }
    }

    /**
     * ================================================================
     * /mclan
     * ================================================================
     */
    private function startClanCreation(
        $message,
        Api $telegram
    ): void {
        $chatId = (int) $message->chat->id;
        $userId = (int) $message->from->id;

        if (!$this->isMainChat($chatId)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ <b>Создание клана доступно только в главном чате.</b>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
         * Пользователь должен быть в главном чате.
         */
        if (!$this->isMainChatMember($telegram, $userId)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Вы должны состоять в главном чате.",
            ]);

            return;
        }

        $clanService = app(ClanService::class);

        /*
         * Один создатель = один клан.
         */
        $existingClan = $clanService->getClanByCreator($userId);

        if ($existingClan) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ <b>У вас уже есть клан.</b>\n\n" .
                    "🏰 <b>" .
                    $this->escapeHtml($existingClan->name) .
                    "</b>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
         * Создаём сессию.
         */
        $session = BotSession::query()->firstOrCreate(
            [
                'telegram_user_id' => $userId,
            ],
            [
                'step' => 'clan_name',
            ]
        );

        $session->update([
            'step' => 'clan_name',
            'temp_clan_name' => null,
            'temp_clan_chat_id' => null,
            'temp_clan_chat_input' => null,
        ]);

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🏰 <b>Создание клана</b>\n\n" .
                "Введите название вашего клана.\n\n" .
                "Например:\n" .
                "<code>Shoh King</code>",
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * ================================================================
     * НАЗВАНИЕ КЛАНА
     * ================================================================
     */
    private function handleClanName(
        $message,
        Api $telegram,
        BotSession $session
    ): bool {
        $chatId = (int) $message->chat->id;

        if (!$this->isMainChat($chatId)) {
            return false;
        }

        $name = trim($message->text ?? '');

        if ($name === '') {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Название клана не может быть пустым.",
            ]);

            return true;
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Название должно быть от 2 до 100 символов.",
            ]);

            return true;
        }

        $session->update([
            'step' => 'clan_chat',
            'temp_clan_name' => $name,
        ]);

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' =>
                "🏰 <b>Название:</b> " .
                $this->escapeHtml($name) .
                "\n\n" .

                "Теперь отправьте Telegram-чат вашего клана.\n\n" .

                "Можно отправить:\n" .
                "• <code>@username</code>\n" .
                "• <code>https://t.me/username</code>\n" .
                "• <code>t.me/username</code>\n" .
                "• ID вида <code>-1001234567890</code>\n\n" .

                "⚠️ Бот должен находиться в этом чате.\n" .
                "⚠️ Вы должны быть владельцем этого чата.",
            'parse_mode' => 'HTML',
        ]);

        return true;
    }

    /**
     * ================================================================
     * ЧАТ КЛАНА
     * ================================================================
     */
    private function handleClanChat(
        $message,
        Api $telegram,
        BotSession $session
    ): bool {
        $mainChatId = (int) $message->chat->id;
        $userId = (int) $message->from->id;

        if (!$this->isMainChat($mainChatId)) {
            return false;
        }

        $chatInput = trim($message->text ?? '');

        if ($chatInput === '') {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ Отправьте @username, ссылку t.me или ID чата.",
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Нормализация
         * ---------------------------------------------------------
         */
        $chatId = $this->normalizeChatId($chatInput);

        if ($chatId === null) {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Не удалось распознать Telegram-чат.</b>\n\n" .

                    "Примеры:\n" .
                    "<code>@ShohKingChat</code>\n" .
                    "<code>https://t.me/ShohKingChat</code>\n" .
                    "<code>t.me/ShohKingChat</code>\n" .
                    "<code>-1001234567890</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        $session->update([
            'temp_clan_chat_input' => $chatInput,
        ]);

        /*
         * ---------------------------------------------------------
         * GET CHAT
         * ---------------------------------------------------------
         */
        try {

            $chat = $telegram->getChat([
                'chat_id' => $chatId,
            ]);

        } catch (Throwable $e) {

            Log::error('Clan getChat failed', [
                'input' => $chatInput,
                'normalized_chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Telegram не смог найти этот чат.</b>\n\n" .

                    "Вы указали:\n" .
                    "<code>" .
                    $this->escapeHtml($chatInput) .
                    "</code>\n\n" .

                    "Telegram получил:\n" .
                    "<code>" .
                    $this->escapeHtml((string) $chatId) .
                    "</code>\n\n" .

                    "Ошибка:\n" .
                    "<code>" .
                    $this->escapeHtml($e->getMessage()) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * Получаем данные чата.
         */
        $realChatId = (int) (
            is_object($chat)
                ? ($chat->id ?? 0)
                : ($chat['id'] ?? 0)
        );

        $chatType = is_object($chat)
            ? ($chat->type ?? null)
            : ($chat['type'] ?? null);

        $chatTitle = is_object($chat)
            ? ($chat->title ?? null)
            : ($chat['title'] ?? null);

        $chatUsername = is_object($chat)
            ? ($chat->username ?? null)
            : ($chat['username'] ?? null);

        if ($realChatId === 0) {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ Telegram вернул неправильные данные о чате.",
            ]);

            return true;
        }

        /*
         * Только группа / супергруппа.
         */
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Этот чат не является группой.</b>\n\n" .
                    "Тип: <code>" .
                    $this->escapeHtml((string) $chatType) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * ПРОВЕРКА ВЛАДЕЛЬЦА
         * ---------------------------------------------------------
         */
        try {

            $member = $telegram->getChatMember([
                'chat_id' => $realChatId,
                'user_id' => $userId,
            ]);

        } catch (Throwable $e) {

            Log::error('Clan owner check failed', [
                'chat_id' => $realChatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Не удалось проверить владельца чата.</b>\n\n" .
                    "Убедитесь, что бот добавлен в чат.\n\n" .
                    "Ошибка Telegram:\n" .
                    "<code>" .
                    $this->escapeHtml($e->getMessage()) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        $memberStatus = is_object($member)
            ? ($member->status ?? null)
            : ($member['status'] ?? null);

        /*
         * Только creator.
         */
        if ($memberStatus !== 'creator') {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Вы не являетесь владельцем этого чата.</b>\n\n" .
                    "Создать клан может только владелец Telegram-чата.",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * ПРОВЕРКА УНИКАЛЬНОСТИ ЧАТА
         * ---------------------------------------------------------
         */
        $clanService = app(ClanService::class);

        $existingChatClan = $clanService->getClanByChatId(
            $realChatId
        );

        if ($existingChatClan) {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Этот чат уже зарегистрирован.</b>\n\n" .
                    "🏰 Клан: <b>" .
                    $this->escapeHtml($existingChatClan->name) .
                    "</b>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * КОЛИЧЕСТВО УЧАСТНИКОВ
         * ---------------------------------------------------------
         */
        $memberCount = 0;

        try {

            $count = $telegram->getChatMembersCount([
                'chat_id' => $realChatId,
            ]);

            $memberCount = (int) $count;

        } catch (Throwable $e) {

            Log::warning('Clan member count failed', [
                'chat_id' => $realChatId,
                'error' => $e->getMessage(),
            ]);

            /*
             * Если Telegram не дал количество,
             * всё равно продолжаем создание.
             */
            $memberCount = 0;
        }

        /*
         * ---------------------------------------------------------
         * СОЗДАНИЕ КЛАНА
         * ---------------------------------------------------------
         */
        try {

            /*
             * ВАЖНО:
             * твой ClanService принимает параметры именно так.
             */
            $clan = $clanService->createClan(
                creatorId: $userId,
                creatorUsername: $message->from->username ?? null,
                name: $session->temp_clan_name,
                chatId: $realChatId,
                chatUsername: $chatUsername,
                chatLink: $this->buildChatLink($chatUsername),
                memberCount: $memberCount
            );

        } catch (Throwable $e) {

            Log::error('Clan creation failed', [
                'user_id' => $userId,
                'chat_id' => $realChatId,
                'name' => $session->temp_clan_name,
                'error' => $e->getMessage(),
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Не удалось создать клан.</b>\n\n" .
                    "Ошибка:\n" .
                    "<code>" .
                    $this->escapeHtml($e->getMessage()) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * СОЗДАЁМ INVITE
         * ---------------------------------------------------------
         *
         * ВАЖНО:
         * ClanService принимает Clan $clan,
         * а не clanId.
         */
        try {

            $invite = $clanService->createInvite(
                clan: $clan,
                createdBy: $userId
            );

        } catch (Throwable $e) {

            Log::error('Clan invite creation failed', [
                'clan_id' => $clan->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            $session->update([
                'step' => null,
                'temp_clan_name' => null,
                'temp_clan_chat_id' => null,
                'temp_clan_chat_input' => null,
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "⚠️ Клан создан, но приглашение создать не удалось.\n\n" .
                    "ID клана: <code>" .
                    $clan->id .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * ОЧИЩАЕМ СЕССИЮ
         * ---------------------------------------------------------
         */
        $session->update([
            'step' => null,
            'temp_clan_name' => null,
            'temp_clan_chat_id' => null,
            'temp_clan_chat_input' => null,
        ]);

        /*
         * ---------------------------------------------------------
         * INVITE LINK
         * ---------------------------------------------------------
         */
        $botUsername = $this->getBotUsername($telegram);

        $inviteLink =
            'https://t.me/' .
            $botUsername .
            '?start=clan_' .
            $invite->token;

        $chatLink = $this->buildChatLink($chatUsername);

        $chatDisplay = $chatTitle;

        if (!$chatDisplay && $chatUsername) {
            $chatDisplay = '@' . $chatUsername;
        }

        if (!$chatDisplay) {
            $chatDisplay = (string) $realChatId;
        }

        /*
         * ---------------------------------------------------------
         * УСПЕХ
         * ---------------------------------------------------------
         */
        $text =
            "🎉 <b>Клан успешно создан!</b>\n\n" .

            "🏰 <b>Клан:</b> " .
            $this->escapeHtml($clan->name) .
            "\n" .

            "💬 <b>Чат:</b> " .
            $this->escapeHtml($chatDisplay) .
            "\n";

        if ($chatLink) {
            $text .=
                "🔗 <a href=\"" .
                $this->escapeHtml($chatLink) .
                "\">Открыть чат</a>\n";
        }

        $text .=
            "\n" .
            "👥 <b>Участников:</b> " .
            $memberCount .
            "\n\n" .
            "📨 Приглашение в клан находится под сообщением.";

        $telegram->sendMessage([
            'chat_id' => $mainChatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '⚔️ Открыть приглашение',
                            'url' => $inviteLink,
                        ],
                    ],
                ],
            ]),
        ]);

        return true;
    }

    /**
     * ================================================================
     * /ms
     * ================================================================
     */
    private function showClans(
        $message,
        Api $telegram
    ): void {
        $chatId = (int) $message->chat->id;

        if (!$this->isMainChat($chatId)) {
            return;
        }

        $clanService = app(ClanService::class);

        $clans = $clanService->getActiveClans();

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

        $text = "🏰 <b>Кланы</b>\n\n";

        foreach ($clans as $index => $clan) {

            /*
             * Обновляем количество участников.
             */
            try {

                $count = $telegram->getChatMembersCount([
                    'chat_id' => $clan->chat_id,
                ]);

                $count = (int) $count;

                if ($count !== (int) $clan->member_count) {
                    $clan->update([
                        'member_count' => $count,
                        'member_count_updated_at' => now(),
                    ]);

                    $clan->refresh();
                }

            } catch (Throwable $e) {

                Log::warning('Clan count update failed', [
                    'clan_id' => $clan->id,
                    'chat_id' => $clan->chat_id,
                    'error' => $e->getMessage(),
                ]);
            }

            /*
             * Имя создателя.
             */
            $creatorName = null;

            if ($clan->creator_username) {

                $creatorName =
                    '@' .
                    ltrim(
                        $clan->creator_username,
                        '@'
                    );

            } else {

                try {

                    $creator = TelegramUser::query()
                        ->where(
                            'telegram_id',
                            $clan->creator_id
                        )
                        ->first();

                    if ($creator) {
                        $creatorName =
                            $creator->first_name
                            ?: 'Неизвестно';
                    }

                } catch (Throwable $e) {

                    Log::warning('Clan creator lookup failed', [
                        'clan_id' => $clan->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$creatorName) {
                $creatorName = 'Неизвестно';
            }

            /*
             * Ссылка на чат.
             */
            $chatLink = $clan->chat_link;

            if (!$chatLink && $clan->chat_username) {
                $chatLink =
                    'https://t.me/' .
                    ltrim($clan->chat_username, '@');
            }

            $text .=
                "<b>" .
                ($index + 1) .
                ". " .
                $this->escapeHtml($clan->name) .
                "</b>\n" .

                "👑 Создатель: " .
                $this->escapeHtml($creatorName) .
                "\n" .

                "👥 Участников: " .
                (int) $clan->member_count .
                "\n";

            if ($chatLink) {
                $text .=
                    "💬 <a href=\"" .
                    $this->escapeHtml($chatLink) .
                    "\">Открыть чат</a>\n";
            }

            $text .= "\n";
        }

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * ================================================================
     * /start clan_TOKEN
     * ================================================================
     */
    private function showClanInvite(
        $message,
        Api $telegram,
        string $token
    ): bool {
        $chatId = (int) $message->chat->id;

        $clanService = app(ClanService::class);

        /*
         * У тебя в ClanService именно getValidInvite().
         */
        $invite = $clanService->getValidInvite($token);

        if (!$invite) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ <b>Приглашение недействительно.</b>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        $clan = $invite->clan;

        if (!$clan || !$clan->isActive()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Этот клан больше не активен.",
            ]);

            return true;
        }

        $chatLink = $clan->chat_link;

        if (!$chatLink && $clan->chat_username) {
            $chatLink =
                'https://t.me/' .
                ltrim($clan->chat_username, '@');
        }

        $text =
            "🏰 <b>Приглашение в клан</b>\n\n" .

            "🏰 <b>" .
            $this->escapeHtml($clan->name) .
            "</b>\n\n" .

            "👥 Участников: " .
            (int) $clan->member_count .
            "\n";

        if ($chatLink) {
            $text .=
                "💬 <a href=\"" .
                $this->escapeHtml($chatLink) .
                "\">Открыть чат клана</a>\n";
        }

        $text .=
            "\nНажмите кнопку ниже, чтобы вступить.";

        $telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => '⚔️ Вступить в клан',
                            'callback_data' => 'clan_join_' . $token,
                        ],
                    ],
                ],
            ]),
        ]);

        return true;
    }

    /**
     * ================================================================
     * CALLBACK JOIN
     * ================================================================
     */
    private function handleCallback(
        $message,
        Api $telegram
    ): bool {
        $callback = $message->callback_query;

        $data = $callback->data ?? '';
        $userId = (int) ($callback->from->id ?? 0);

        if (!preg_match(
            '/^clan_join_([A-Za-z0-9]+)$/',
            $data,
            $matches
        )) {
            return false;
        }

        $token = $matches[1];

        try {

            $clanService = app(ClanService::class);

            /*
             * ВАЖНО:
             * используем существующий метод.
             */
            $invite = $clanService->getValidInvite($token);

            if (!$invite) {

                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->id,
                    'text' => '❌ Приглашение недействительно.',
                    'show_alert' => true,
                ]);

                return true;
            }

            $clan = $invite->clan;

            if (!$clan || !$clan->isActive()) {

                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->id,
                    'text' => '❌ Клан больше не активен.',
                    'show_alert' => true,
                ]);

                return true;
            }

            /*
             * Проверяем главный чат.
             */
            if (!$this->isMainChatMember(
                $telegram,
                $userId
            )) {

                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->id,
                    'text' =>
                        '❌ Сначала вступите в главный чат.',
                    'show_alert' => true,
                ]);

                return true;
            }

            /*
             * -----------------------------------------------------
             * Проверяем, есть ли пользователь в другом клане.
             *
             * В ClanService такого метода нет,
             * поэтому проверяем напрямую через ClanMember.
             * -----------------------------------------------------
             */
            $existingMember = ClanMember::query()
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->with('clan')
                ->first();

            if ($existingMember) {

                if (
                    $existingMember->clan
                    && (int) $existingMember->clan->id === (int) $clan->id
                ) {

                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback->id,
                        'text' =>
                            'Вы уже состоите в этом клане.',
                        'show_alert' => true,
                    ]);

                } else {

                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback->id,
                        'text' =>
                            '❌ Вы уже состоите в другом клане.',
                        'show_alert' => true,
                    ]);
                }

                return true;
            }

            /*
             * Вступаем.
             */
            $clanService->useInvite(
                invite: $invite,
                userId: $userId
            );

            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback->id,
                'text' => '⚔️ Вы вступили в клан!',
                'show_alert' => false,
            ]);

            /*
             * Получаем chat_id сообщения.
             */
            $callbackChatId = 0;

            if (isset($callback->message->chat->id)) {
                $callbackChatId =
                    (int) $callback->message->chat->id;
            }

            if ($callbackChatId === 0) {
                return true;
            }

            $chatLink = $clan->chat_link;

            if (!$chatLink && $clan->chat_username) {
                $chatLink =
                    'https://t.me/' .
                    ltrim($clan->chat_username, '@');
            }

            $text =
                "⚔️ <b>Вы вступили в клан!</b>\n\n" .

                "🏰 <b>" .
                $this->escapeHtml($clan->name) .
                "</b>\n\n" .

                "👥 Участников: " .
                (int) $clan->member_count .
                "\n";

            if ($chatLink) {
                $text .=
                    "\n💬 <a href=\"" .
                    $this->escapeHtml($chatLink) .
                    "\">Открыть чат клана</a>";
            }

            $telegram->sendMessage([
                'chat_id' => $callbackChatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return true;

        } catch (Throwable $e) {

            Log::error('Clan join callback failed', [
                'user_id' => $userId,
                'token' => $token,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->id,
                    'text' =>
                        '❌ ' . $e->getMessage(),
                    'show_alert' => true,
                ]);
            } catch (Throwable $ignore) {
            }

            return true;
        }
    }

    /**
     * ================================================================
     * MAIN CHAT
     * ================================================================
     */
    private function isMainChat(int $chatId): bool
    {
        return $chatId === self::MAIN_CHAT_ID;
    }

    /**
     * ================================================================
     * MAIN CHAT MEMBER
     * ================================================================
     */
    private function isMainChatMember(
        Api $telegram,
        int $userId
    ): bool {
        try {

            $member = $telegram->getChatMember([
                'chat_id' => self::MAIN_CHAT_ID,
                'user_id' => $userId,
            ]);

            $status = is_object($member)
                ? ($member->status ?? null)
                : ($member['status'] ?? null);

            if (in_array(
                $status,
                [
                    'creator',
                    'administrator',
                    'member',
                ],
                true
            )) {
                return true;
            }

            if ($status === 'restricted') {

                $isMember = is_object($member)
                    ? ($member->is_member ?? false)
                    : ($member['is_member'] ?? false);

                return (bool) $isMember;
            }

            return false;

        } catch (Throwable $e) {

            Log::warning('Main chat member check failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ================================================================
     * NORMALIZE CHAT ID
     * ================================================================
     */
    private function normalizeChatId(
        string $input
    ): string|int|null {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        /*
         * ID:
         * -1001234567890
         */
        if (preg_match('/^-?\d+$/', $input)) {
            return (int) $input;
        }

        /*
         * URL.
         */
        $input = preg_replace(
            '#^https?://(?:www\.)?(?:t\.me|telegram\.me)/#i',
            '',
            $input
        );

        /*
         * t.me/username
         */
        $input = preg_replace(
            '#^(?:www\.)?(?:t\.me|telegram\.me)/#i',
            '',
            $input
        );

        /*
         * @username
         */
        $input = ltrim($input, '@');

        /*
         * Убираем slash.
         */
        $input = trim($input, '/');

        /*
         * Убираем ?query и #fragment.
         */
        $input = preg_replace(
            '/[?#].*$/',
            '',
            $input
        );

        $input = trim($input, '/ ');

        if ($input === '') {
            return null;
        }

        /*
         * Telegram username.
         */
        if (preg_match(
            '/^[A-Za-z0-9_]{5,32}$/',
            $input
        )) {
            return '@' . $input;
        }

        return null;
    }

    /**
     * ================================================================
     * CHAT LINK
     * ================================================================
     */
    private function buildChatLink(
        ?string $username
    ): ?string {
        if (!$username) {
            return null;
        }

        return 'https://t.me/' .
            ltrim($username, '@');
    }

    /**
     * ================================================================
     * BOT USERNAME
     * ================================================================
     */
    private function getBotUsername(
        Api $telegram
    ): string {
        try {

            $me = $telegram->getMe();

            $username = is_object($me)
                ? ($me->username ?? null)
                : ($me['username'] ?? null);

            if ($username) {
                return ltrim($username, '@');
            }

        } catch (Throwable $e) {

            Log::warning('getMe failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return 'YkSUS10_bot';
    }

    /**
     * ================================================================
     * HTML ESCAPE
     * ================================================================
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