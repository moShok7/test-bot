<?php

namespace App\Services\Bot\Clan;

use App\Models\BotSession;
use App\Models\Clan;
use App\Models\TelegramUser;
use App\Services\Clan\ClanService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;
use Throwable;

class ClanHandler
{
    /**
     * Главный чат, где работает система кланов.
     */
    private const MAIN_CHAT_ID = -1004344778682;

    public function handle($message, Api $telegram): bool
    {
        try {
            $text = trim($message->text ?? '');
            $chatId = (int) ($message->chat->id ?? 0);
            $userId = (int) ($message->from->id ?? 0);

            if ($chatId === 0 || $userId === 0) {
                return false;
            }

            /*
             * ---------------------------------------------------------
             * CALLBACK QUERY
             * ---------------------------------------------------------
             */
            if (isset($message->callback_query)) {
                return $this->handleCallback($message, $telegram);
            }

            /*
             * ---------------------------------------------------------
             * DEEP LINK
             *
             * /start clan_TOKEN
             * ---------------------------------------------------------
             */
            if (preg_match('/^\/start(?:@\w+)?\s+clan_([A-Za-z0-9]+)$/i', $text, $matches)) {
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
                $this->startClanCreation($message, $telegram);

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

                $this->showClans($message, $telegram);

                return true;
            }

            /*
             * ---------------------------------------------------------
             * /mt
             *
             * Передаём отдельному обработчику модерации.
             * ---------------------------------------------------------
             */
            if (preg_match('/^\/mt(?:@\w+)?(?:\s+.*)?$/i', $text)) {
                return false;
            }

            /*
             * ---------------------------------------------------------
             * /mr
             *
             * Передаём отдельному обработчику модерации.
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
            $session = BotSession::where(
                'telegram_user_id',
                $userId
            )->first();

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

            Log::error('ClanHandler error', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                $telegram->sendMessage([
                    'chat_id' => $message->chat->id,
                    'text' => '❌ Произошла ошибка при обработке команды.',
                ]);
            } catch (Throwable $sendException) {
                Log::error('ClanHandler error message failed', [
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
    private function startClanCreation($message, Api $telegram): void
    {
        $chatId = (int) $message->chat->id;
        $userId = (int) $message->from->id;

        /*
         * Создание клана только из главного чата.
         */
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
         * Проверяем, состоит ли пользователь в главном чате.
         */
        if (!$this->isMainChatMember($telegram, $userId)) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Вы должны состоять в главном чате.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        $clanService = app(ClanService::class);

        /*
         * У одного Telegram-пользователя может быть только один клан.
         */
        $existingClan = $clanService->getClanByCreator($userId);

        if ($existingClan) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ <b>У вас уже есть клан.</b>\n\n" .
                    "🏰 <b>{$this->escapeHtml($existingClan->name)}</b>",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        /*
         * Создаём/очищаем сессию.
         */
        $session = BotSession::firstOrCreate(
            [
                'telegram_user_id' => $userId,
            ],
            [
                'step' => null,
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
                "Введите <b>название клана</b>.\n\n" .
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
                'text' => '❌ Название клана не может быть пустым.',
            ]);

            return true;
        }

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ Название должно содержать от 2 до 100 символов.",
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
                "Теперь отправьте <b>Telegram-чат клана</b>.\n\n" .
                "Можно отправить:\n" .
                "• <code>@username</code>\n" .
                "• <code>https://t.me/username</code>\n" .
                "• <code>t.me/username</code>\n" .
                "• ID вида <code>-1001234567890</code>\n\n" .
                "⚠️ Бот должен находиться в этом чате, а вы должны быть его владельцем.",
            'parse_mode' => 'HTML',
        ]);

        return true;
    }

    /**
     * ================================================================
     * TELEGRAM-ЧАТ КЛАНА
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
                    "❌ Отправьте username, ссылку на Telegram-чат или его ID.",
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Нормализуем введённый чат.
         * ---------------------------------------------------------
         */
        $chatId = $this->normalizeChatId($chatInput);

        if ($chatId === null) {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Не удалось распознать Telegram-чат.</b>\n\n" .
                    "Отправьте:\n" .
                    "• <code>@username</code>\n" .
                    "• <code>https://t.me/username</code>\n" .
                    "• <code>t.me/username</code>\n" .
                    "• ID вида <code>-1001234567890</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * Сохраняем исходный ввод.
         */
        $session->update([
            'temp_clan_chat_input' => $chatInput,
        ]);

        /*
         * ---------------------------------------------------------
         * Получаем информацию о Telegram-чате.
         * ---------------------------------------------------------
         */
        try {
            $chat = $telegram->getChat([
                'chat_id' => $chatId,
            ]);

        } catch (Throwable $e) {

            Log::error('Clan chat lookup failed', [
                'input' => $chatInput,
                'normalized_chat_id' => $chatId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Telegram не смог найти этот чат.</b>\n\n" .
                    "📌 Вы указали:\n" .
                    "<code>" .
                    $this->escapeHtml($chatInput) .
                    "</code>\n\n" .
                    "🔎 Telegram получил:\n" .
                    "<code>" .
                    $this->escapeHtml((string) $chatId) .
                    "</code>\n\n" .
                    "⚠️ Ошибка Telegram:\n" .
                    "<code>" .
                    $this->escapeHtml($e->getMessage()) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * Telegram SDK может вернуть объект или массив.
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
                    "❌ Telegram вернул некорректные данные о чате.",
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Проверяем тип чата.
         *
         * Клан может быть только group/supergroup.
         * ---------------------------------------------------------
         */
        if (!in_array($chatType, ['group', 'supergroup'], true)) {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Это не групповой чат.</b>\n\n" .
                    "Тип найденного чата: " .
                    "<code>" .
                    $this->escapeHtml((string) $chatType) .
                    "</code>\n\n" .
                    "Клан можно создать только в группе или супергруппе.",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Проверяем, что пользователь является OWNER.
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
                'exception' => get_class($e),
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Не удалось проверить владельца чата.</b>\n\n" .
                    "Убедитесь, что бот находится в этом чате.\n\n" .
                    "⚠️ Ошибка Telegram:\n" .
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

        if ($memberStatus !== 'creator') {
            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Вы не являетесь владельцем этого чата.</b>\n\n" .
                    "Создать клан может только настоящий владелец Telegram-чата.",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Проверяем, не зарегистрирован ли уже этот чат.
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
                    "🏰 Клан: " .
                    "<b>" .
                    $this->escapeHtml($existingChatClan->name) .
                    "</b>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Получаем количество участников.
         *
         * Если Telegram не отдаст count, НЕ ломаем создание клана.
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
                'chat_username' => $chatUsername,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            /*
             * Не останавливаем регистрацию.
             */
            $memberCount = 0;
        }

        /*
         * ---------------------------------------------------------
         * Создаём клан.
         * ---------------------------------------------------------
         */
        try {

            $clan = $clanService->createClan(
                creatorId: $userId,
                name: $session->temp_clan_name,
                chatId: $realChatId,
                creatorUsername: $message->from->username ?? null,
                chatUsername: $chatUsername,
                chatLink: $this->buildChatLink(
                    $chatUsername,
                    $realChatId
                ),
                memberCount: $memberCount
            );

        } catch (Throwable $e) {

            Log::error('Clan creation failed', [
                'user_id' => $userId,
                'chat_id' => $realChatId,
                'name' => $session->temp_clan_name,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            $telegram->sendMessage([
                'chat_id' => $mainChatId,
                'text' =>
                    "❌ <b>Не удалось создать клан.</b>\n\n" .
                    "⚠️ Ошибка:\n" .
                    "<code>" .
                    $this->escapeHtml($e->getMessage()) .
                    "</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Создаём invite.
         * ---------------------------------------------------------
         */
        try {

            $invite = $clanService->createInvite(
                clanId: $clan->id,
                createdBy: $userId
            );

        } catch (Throwable $e) {

            Log::error('Clan invite creation failed', [
                'clan_id' => $clan->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
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
                    "⚠️ Клан создан, но не удалось создать приглашение.\n\n" .
                    "ID клана: <code>{$clan->id}</code>",
                'parse_mode' => 'HTML',
            ]);

            return true;
        }

        /*
         * ---------------------------------------------------------
         * Очищаем сессию.
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
         * Ссылка на приглашение.
         * ---------------------------------------------------------
         */
        $botUsername = $this->getBotUsername($telegram);

        $inviteLink =
            'https://t.me/' .
            $botUsername .
            '?start=clan_' .
            $invite->token;

        $chatLink = $this->buildChatLink(
            $chatUsername,
            $realChatId
        );

        $chatTitleText = $chatTitle
            ?: ($chatUsername ? '@' . $chatUsername : (string) $realChatId);

        /*
         * ---------------------------------------------------------
         * Успешное сообщение.
         * ---------------------------------------------------------
         */
        $text =
            "🎉 <b>Клан успешно создан!</b>\n\n" .

            "🏰 <b>Клан:</b> " .
            $this->escapeHtml($clan->name) .
            "\n" .

            "💬 <b>Чат:</b> " .
            $this->escapeHtml($chatTitleText) .
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
            "📨 Теперь отправляйте приглашение другим игрокам.";

        $telegram->sendMessage([
            'chat_id' => $mainChatId,
            'text' => $text,
            'parse_mode' => 'HTML',
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
    private function showClans($message, Api $telegram): void
    {
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
                    "Пока нет зарегистрированных кланов.",
                'parse_mode' => 'HTML',
            ]);

            return;
        }

        $text =
            "🏰 <b>Кланы</b>\n\n";

        foreach ($clans as $index => $clan) {

            /*
             * Пытаемся обновить количество участников.
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

                Log::warning('Clan /ms member count update failed', [
                    'clan_id' => $clan->id,
                    'chat_id' => $clan->chat_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $creatorName = null;

            if ($clan->creator_username) {
                $creatorName = '@' . ltrim(
                    $clan->creator_username,
                    '@'
                );
            } else {
                try {

                    $creator = TelegramUser::where(
                        'telegram_id',
                        $clan->creator_id
                    )->first();

                    if ($creator) {
                        $creatorName =
                            $creator->first_name
                            ?: 'Неизвестно';
                    }

                } catch (Throwable $e) {

                    Log::warning('Clan creator lookup failed', [
                        'clan_id' => $clan->id,
                        'creator_id' => $clan->creator_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (!$creatorName) {
                $creatorName = 'Неизвестно';
            }

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
                    "\">Чат клана</a>\n";
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
     * DEEP LINK
     *
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

        $invite = $clanService->getInviteByToken($token);

        if (!$invite || !$invite->isValid()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "❌ <b>Приглашение недействительно.</b>\n\n" .
                    "Оно могло истечь, быть отключено или достигнуть лимита.",
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
            "🏰 <b>Вас приглашают в клан!</b>\n\n" .

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
     * CALLBACK
     * ================================================================
     */
    private function handleCallback($message, Api $telegram): bool
    {
        $callback = $message->callback_query;

        $data = $callback->data ?? '';
        $userId = (int) ($callback->from->id ?? 0);
        $callbackMessage = $callback->message ?? null;

        if (!preg_match('/^clan_join_([A-Za-z0-9]+)$/', $data, $matches)) {
            return false;
        }

        $token = $matches[1];

        try {

            $clanService = app(ClanService::class);

            $invite = $clanService->getInviteByToken($token);

            if (!$invite || !$invite->isValid()) {

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
             * Проверяем, находится ли пользователь в главном чате.
             */
            if (!$this->isMainChatMember($telegram, $userId)) {

                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->id,
                    'text' =>
                        '❌ Сначала вступите в главный чат.',
                    'show_alert' => true,
                ]);

                return true;
            }

            /*
             * Проверяем, не состоит ли уже пользователь в другом клане.
             */
            $existingMember = $clanService->getActiveClanForUser(
                $userId
            );

            if ($existingMember) {

                if ((int) $existingMember->id === (int) $clan->id) {

                    $telegram->answerCallbackQuery([
                        'callback_query_id' => $callback->id,
                        'text' => 'Вы уже состоите в этом клане.',
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
             * Добавляем пользователя.
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
             * Сообщение пользователю.
             */
            $chatLink = $clan->chat_link;

            if (!$chatLink && $clan->chat_username) {
                $chatLink =
                    'https://t.me/' .
                    ltrim($clan->chat_username, '@');
            }

            $text =
                "⚔️ <b>Добро пожаловать в клан!</b>\n\n" .
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
                'chat_id' => $callbackMessage->chat->id,
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

            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback->id,
                'text' => '❌ Не удалось вступить в клан.',
                'show_alert' => true,
            ]);

            return true;
        }
    }

    /**
     * ================================================================
     * ПРОВЕРКА ГЛАВНОГО ЧАТА
     * ================================================================
     */
    private function isMainChat(int $chatId): bool
    {
        return $chatId === self::MAIN_CHAT_ID;
    }

    /**
     * ================================================================
     * ПРОВЕРКА УЧАСТНИКА ГЛАВНОГО ЧАТА
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
                ['creator', 'administrator', 'member'],
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

            Log::warning('Main chat membership check failed', [
                'user_id' => $userId,
                'chat_id' => self::MAIN_CHAT_ID,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * ================================================================
     * НОРМАЛИЗАЦИЯ TELEGRAM CHAT ID
     * ================================================================
     */
    private function normalizeChatId(string $input): string|int|null
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        /*
         * Numeric ID.
         *
         * Например:
         * -1001234567890
         */
        if (preg_match('/^-?\d+$/', $input)) {
            return (int) $input;
        }

        /*
         * Убираем пробелы.
         */
        $input = trim($input);

        /*
         * https://t.me/username
         * http://t.me/username
         * https://telegram.me/username
         * http://telegram.me/username
         */
        $input = preg_replace(
            '#^https?://(?:www\.)?(?:t\.me|telegram\.me)/#i',
            '',
            $input
        );

        /*
         * t.me/username
         * telegram.me/username
         */
        $input = preg_replace(
            '#^(?:www\.)?(?:t\.me|telegram\.me)/#i',
            '',
            $input
        );

        /*
         * Убираем @.
         */
        $input = ltrim($input, '@');

        /*
         * Убираем /.
         */
        $input = trim($input, '/');

        /*
         * Убираем query string и fragment.
         *
         * Например:
         * ShohKingChat?foo=bar
         */
        $input = preg_replace('/[?#].*$/', '', $input);

        $input = trim($input, '/ ');

        if ($input === '') {
            return null;
        }

        /*
         * Telegram username:
         * от 5 до 32 символов.
         */
        if (preg_match('/^[A-Za-z0-9_]{5,32}$/', $input)) {
            return '@' . $input;
        }

        return null;
    }

    /**
     * ================================================================
     * ССЫЛКА НА ЧАТ
     * ================================================================
     */
    private function buildChatLink(
        ?string $username,
        int $chatId
    ): ?string {
        if ($username) {
            return 'https://t.me/' .
                ltrim($username, '@');
        }

        /*
         * Для приватных чатов по одному ID публичную ссылку
         * построить нельзя.
         */
        return null;
    }

    /**
     * ================================================================
     * USERNAME БОТА
     * ================================================================
     */
    private function getBotUsername(Api $telegram): string
    {
        try {

            $me = $telegram->getMe();

            $username = is_object($me)
                ? ($me->username ?? null)
                : ($me['username'] ?? null);

            if ($username) {
                return ltrim($username, '@');
            }

        } catch (Throwable $e) {

            Log::warning('Unable to get bot username', [
                'error' => $e->getMessage(),
            ]);
        }

        /*
         * Запасной вариант.
         */
        return 'YkSUS10_bot';
    }

    /**
     * ================================================================
     * HTML ESCAPE
     * ================================================================
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