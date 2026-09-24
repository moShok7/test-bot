<?php

namespace App\Services\Telegram;

use App\Models\BotGroup;

class UserTelegramGroupsService
{
    public function __construct(
        private UserTelegramService $telegramService
    ) {
    }

    /**
     * Возвращает группы аккаунта @moShok7,
     * которых нет среди групп YkSUS.
     */
    public function getExternalGroups(): array
    {
        $telegram = $this->telegramService->getClient();

        $dialogIds = $telegram->getDialogIds();

        /*
         * Получаем известные YkSUS-группы один раз,
         * чтобы не делать SQL-запрос для каждого диалога.
         */
        $knownChatIds = BotGroup::query()
            ->pluck('chat_id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        $groups = [];

        foreach ($dialogIds as $peer) {
            try {
                $chat = $telegram->getPwrChat($peer);

                $type = $chat['type'] ?? null;

                if (
                    $type !== 'group' &&
                    $type !== 'supergroup'
                ) {
                    continue;
                }

                $chatId = (int) ($chat['id'] ?? 0);

                if (!$chatId) {
                    continue;
                }

                /*
                 * Если группа уже есть в bot_groups,
                 * её отправляем через обычный Bot API.
                 */
                if ($knownChatIds->has($chatId)) {
                    continue;
                }

                $groups[] = [
                    'chat_id' => $chatId,
                    'title' => $chat['title'] ?? 'Без названия',
                    'type' => $type,
                ];

            } catch (\Throwable) {
                /*
                 * Например CHANNEL_MONOFORUM_UNSUPPORTED.
                 * Просто пропускаем проблемный диалог.
                 */
                continue;
            }
        }

        return $groups;
    }
}