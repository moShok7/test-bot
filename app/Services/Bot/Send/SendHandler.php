<?php

namespace App\Services\Bot\Send;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

class SendHandler
{
    /**
     * Файлы инструкции:
     * storage/app/public/send/
     */
    private const FILES_PATH = 'app/public/send';

    public function handle($message, Api $telegram): bool
    {
        $text = trim((string) ($message->text ?? ''));

        // Обрабатываем только /send и /send@BotName
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

            // Проверяем наличие всех файлов
            $files = [
                $melonZip,
                $modDll,
                $image1,
                $image2,
            ];

            foreach ($files as $file) {
                if (!is_file($file) || !is_readable($file)) {
                    Log::error('SendHandler: file missing or unreadable', [
                        'file' => $file,
                    ]);

                    $telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => '❌ Файл не найден или недоступен: '
                            . basename($file),
                    ]);

                    return true;
                }
            }

            Log::info('SendHandler: starting instruction', [
                'chat_id' => $chatId,
            ]);

            // ЗАГОЛОВОК
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

            // Отправляем MelonLoader.zip
            Log::info('SendHandler: sending MelonLoader.zip');

            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => InputFile::create(
                    $melonZip,
                    'MelonLoader.zip'
                ),
                'caption' => '⬇️ MelonLoader.zip',
            ]);

            // Отправляем первую фотографию
            Log::info('SendHandler: sending step1.jpg');

            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => InputFile::create(
                    $image1,
                    'step1.jpg'
                ),
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

            // Отправляем DLL
            Log::info('SendHandler: sending MyMod.dll');

            $telegram->sendDocument([
                'chat_id' => $chatId,
                'document' => InputFile::create(
                    $modDll,
                    'MyMod.dll'
                ),
                'caption' => '⬇️ MyMod.dll',
            ]);

            // Отправляем вторую фотографию
            Log::info('SendHandler: sending step2.jpg');

            $telegram->sendPhoto([
                'chat_id' => $chatId,
                'photo' => InputFile::create(
                    $image2,
                    'step2.jpg'
                ),
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

            Log::info('SendHandler: instruction sent successfully', [
                'chat_id' => $chatId,
            ]);

        } catch (\Throwable $e) {
            Log::error('SendHandler error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'chat_id' => $chatId,
            ]);

            try {
                $telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' =>
                        '❌ Не удалось отправить инструкцию. '
                        . 'Попробуй позже.',
                ]);
            } catch (\Throwable $ignored) {
                Log::error('SendHandler: failed to send error message', [
                    'error' => $ignored->getMessage(),
                ]);
            }
        }

        return true;
    }
}
