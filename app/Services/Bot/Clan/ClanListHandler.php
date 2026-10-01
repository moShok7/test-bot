<?php

namespace App\Services\Clan;

use Telegram\Bot\Api;

class ClanListHandler
{
    private const PER_PAGE = 10;

    public function __construct(
        private ClanService $clanService
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Основная команда
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

        $chatId = $message->chat->id ?? $telegramId;

        /*
        |--------------------------------------------------------------------------
        | /ms
        |--------------------------------------------------------------------------
        */

        if (
            $text !== '/ms' &&
            !str_starts_with($text, '/ms@')
        ) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Получаем кланы
        |--------------------------------------------------------------------------
        */

        $clans = $this->clanService->getActiveClans(
            self::PER_PAGE
        );

        if ($clans->isEmpty()) {
            $telegram->sendMessage([
                'chat_id' => $chatId,

                'text' =>
                    "🏰 Кланы\n\n" .
                    "Пока зарегистрированных кланов нет."
            ]);

            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Формируем список
        |--------------------------------------------------------------------------
        */

        $output =
            "🏰 <b>Зарегистрированные кланы</b>\n\n";

        foreach ($clans as $index => $clan) {
            $number =
                $clans->firstItem() + $index;

            $creator = $clan->creator_username
                ? '@' . ltrim(
                    $clan->creator_username,
                    '@'
                )
                : 'Игрок';

            $clanMembers =
                $clan->activeMembersCount();

            $output .=
                "<b>{$number}. {$this->escapeHtml($clan->name)}</b>\n" .
                "👑 {$this->escapeHtml($creator)}\n" .
                "👥 В чате: <b>" .
                number_format(
                    $clan->member_count,
                    0,
                    '.',
                    ' '
                ) .
                "</b>\n" .
                "⚔️ В клане: <b>" .
                number_format(
                    $clanMembers,
                    0,
                    '.',
                    ' '
                ) .
                "</b>\n";

            if ($clan->chat_link) {
                $output .=
                    "💬 <a href=\"" .
                    $this->escapeAttribute(
                        $clan->chat_link
                    ) .
                    "\">Telegram-чат</a>\n";
            }

            $output .= "\n";
        }

        /*
        |--------------------------------------------------------------------------
        | Пагинация
        |--------------------------------------------------------------------------
        */

        $buttons = [];

        if ($clans->currentPage() > 1) {
            $buttons[] = [
                [
                    'text' => '⬅️',
                    'callback_data' =>
                        'clan_list_' .
                        ($clans->currentPage() - 1),
                ],
            ];
        }

        if ($clans->hasMorePages()) {
            $buttons[] = [
                [
                    'text' => '➡️',
                    'callback_data' =>
                        'clan_list_' .
                        ($clans->currentPage() + 1),
                ],
            ];
        }

        $replyMarkup = null;

        if (!empty($buttons)) {
            $replyMarkup = json_encode([
                'inline_keyboard' => $buttons,
            ]);
        }

        $params = [
            'chat_id' => $chatId,

            'text' => $output,

            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }

        $telegram->sendMessage($params);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Callback пагинации
    |--------------------------------------------------------------------------
    */

    public function handleCallback(
        $callback,
        Api $telegram
    ): bool {
        $callbackData = $callback->data ?? '';

        if (!str_starts_with(
            $callbackData,
            'clan_list_'
        )) {
            return false;
        }

        $page = (int) str_replace(
            'clan_list_',
            '',
            $callbackData
        );

        if ($page < 1) {
            $page = 1;
        }

        $clans = $this->clanService->getActiveClans(
            self::PER_PAGE
        );

        $clans->setCurrentPage($page);

        /*
        |--------------------------------------------------------------------------
        | Перезапрашиваем страницу
        |--------------------------------------------------------------------------
        */

        $clans = \App\Models\Clan::query()
            ->active()
            ->orderByDesc('member_count')
            ->paginate(
                self::PER_PAGE,
                ['*'],
                'page',
                $page
            );

        if ($clans->isEmpty()) {
            try {
                $telegram->answerCallbackQuery([
                    'callback_query_id' => $callback->id,

                    'text' => 'Эта страница пуста.',
                ]);
            } catch (\Throwable $e) {
            }

            return true;
        }

        $output =
            "🏰 <b>Зарегистрированные кланы</b>\n\n";

        foreach ($clans as $index => $clan) {
            $number =
                $clans->firstItem() + $index;

            $creator = $clan->creator_username
                ? '@' . ltrim(
                    $clan->creator_username,
                    '@'
                )
                : 'Игрок';

            $output .=
                "<b>{$number}. {$this->escapeHtml($clan->name)}</b>\n" .
                "👑 {$this->escapeHtml($creator)}\n" .
                "👥 В чате: <b>" .
                number_format(
                    $clan->member_count,
                    0,
                    '.',
                    ' '
                ) .
                "</b>\n" .
                "⚔️ В клане: <b>" .
                number_format(
                    $clan->activeMembersCount(),
                    0,
                    '.',
                    ' '
                ) .
                "</b>\n";

            if ($clan->chat_link) {
                $output .=
                    "💬 <a href=\"" .
                    $this->escapeAttribute(
                        $clan->chat_link
                    ) .
                    "\">Telegram-чат</a>\n";
            }

            $output .= "\n";
        }

        $buttons = [];

        if ($clans->currentPage() > 1) {
            $buttons[] = [
                [
                    'text' => '⬅️',

                    'callback_data' =>
                        'clan_list_' .
                        ($clans->currentPage() - 1),
                ],
            ];
        }

        if ($clans->hasMorePages()) {
            $buttons[] = [
                [
                    'text' => '➡️',

                    'callback_data' =>
                        'clan_list_' .
                        ($clans->currentPage() + 1),
                ],
            ];
        }

        $params = [
            'chat_id' =>
                $callback->message->chat->id,

            'message_id' =>
                $callback->message->messageId,

            'text' => $output,

            'parse_mode' => 'HTML',
        ];

        if (!empty($buttons)) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $buttons,
            ]);
        }

        try {
            $telegram->editMessageText($params);

            $telegram->answerCallbackQuery([
                'callback_query_id' => $callback->id,
            ]);
        } catch (\Throwable $e) {
            \Log::warning(
                'Clan list pagination failed',
                [
                    'error' => $e->getMessage(),
                ]
            );
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | HTML escaping
    |--------------------------------------------------------------------------
    */

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
} 