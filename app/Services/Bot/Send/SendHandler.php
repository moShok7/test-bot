<?php

namespace App\Services\Bot\Send;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class SendHandler
{
    /**
     * Файлы инструкции.
     *
     * storage/app/public/send/
     */
    private const FILES_PATH = 'app/public/send';

    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));

        // Только команда /send
        if (!preg_match('/^\/send(?:@\w+)?$/i', $text)) {
            return false;
        }

        $chatId = $message->chat->id ?? null;

        if (!$chatId) {
            return true;
        }

        try {
            $basePath = storage_path(self::FILES_PATH);

            $melonZip = $basePath . '/MelonLoader.zip';
            $modDll = $basePath . '/MyMod.dll';

            $image1 = $basePath . '/step1.jpg';
            $image2 = $basePath . '/step2.jpg';

            // Проверяем наличие файлов
            foreach ([
                $melonZip,
                $modDll,
                $image1,
                $image2,
            ] as $file) {
                if (!file_exists($file)) {
                    Log::warning('SendHandler: file not found', [
                        'file' => $file,
                    ]);

                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => '❌ Не найден файл инструкции: '
                            . basename($file),
                    ]);

                    return true;
                }
            }

            // Заголовок
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🍈 УСТАНОВКА МОЕГО МОДА\n\n"
                    . "Следуй инструкции по шагам.\n"
                    . "Не пропускай этапы установки!",
            ]);

            // ШАГ 1
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "📦 ШАГ 1. MELONLOADER\n\n"
                    . "1. Скачай ZIP-архив ниже.\n"
                    . "2. Распакуй архив.\n"
                    . "3. Открой папку с распакованными файлами.\n"
                    . "4. Перенеси необходимые файлы в корневую "
                    . "папку игры, где находится EXE-файл игры.\n\n"
                    . "⚠️ Не создавай дополнительную вложенную папку.",
            ]);

            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => $melonZip,
                'caption' => '⬇️ MelonLoader.zip',
            ]);

            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => $image1,
                'caption' =>
                    '📸 Пример расположения файлов MelonLoader.',
            ]);

            // ШАГ 2
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "🧩 ШАГ 2. УСТАНОВКА МОЕГО МОДА\n\n"
                    . "1. Скачай DLL-файл ниже.\n"
                    . "2. Открой папку игры.\n"
                    . "3. Найди папку MelonLoader/Mods.\n"
                    . "4. Перемести DLL-файл внутрь папки Mods.",
            ]);

            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => $modDll,
                'caption' => '⬇️ MyMod.dll',
            ]);

            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => $image2,
                'caption' =>
                    '📸 Сюда необходимо поместить DLL-файл.',
            ]);

            // ЗАВЕРШЕНИЕ
            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text' =>
                    "✅ УСТАНОВКА ЗАВЕРШЕНА!\n\n"
                    . "Теперь запусти игру и проверь загрузку мода.\n\n"
                    . "Если возникли проблемы, обратись к администрации.",
            ]);

        } catch (\Throwable $e) {
            Log::error('SendHandler error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            try {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Не удалось отправить инструкцию. Попробуй позже.',
                ]);
            } catch (\Throwable $ignored) {
                // Не прерываем работу бота.
            }
        }

        return true;
    }
}
