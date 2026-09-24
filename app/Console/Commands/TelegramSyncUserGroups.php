<?php

namespace App\Console\Commands;

use App\Models\BotGroup;
use App\Services\Telegram\UserTelegramService;
use Illuminate\Console\Command;

class TelegramSyncUserGroups extends Command
{
    protected $signature = 'telegram:sync-user-groups';

    protected $description = 'Синхронизировать группы Telegram-аккаунта с bot_groups';

    public function handle(UserTelegramService $telegramService): int
    {
        $telegram = $telegramService->getClient();

        $this->info('Получаю группы Telegram-аккаунта...');

        $dialogIds = $telegram->getDialogIds();

        $found = 0;
        $added = 0;
        $existing = 0;

        foreach ($dialogIds as $peer) {
            try {
                $chat = $telegram->getPwrChat($peer);

                $type = $chat['type'] ?? null;

                // Нас интересуют только группы
                if ($type !== 'group' && $type !== 'supergroup') {
                    continue;
                }

                $chatId = (int) ($chat['id'] ?? 0);
                $title = $chat['title'] ?? 'Без названия';

                if (!$chatId) {
                    continue;
                }

                $found++;

                $group = BotGroup::where('chat_id', $chatId)->first();

                if ($group) {
                    $existing++;

                    $this->line(
                        "EXISTS | {$chatId} | {$title}"
                    );

                    continue;
                }

                BotGroup::create([
                    'chat_id' => $chatId,
                    'title' => $title,
                    'type' => $type,
                    'is_active' => true,
                ]);

                $added++;

                $this->info(
                    "ADDED  | {$chatId} | {$title}"
                );
            } catch (\Throwable $e) {
                $this->warn(
                    'Не удалось обработать чат: ' . $e->getMessage()
                );
            }
        }

        $this->newLine();

        $this->info("Всего групп найдено: {$found}");
        $this->info("Уже были в YkSUS: {$existing}");
        $this->info("Добавлено новых: {$added}");

        return self::SUCCESS;
    }
}