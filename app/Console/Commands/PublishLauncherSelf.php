<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishLauncherSelf extends Command
{
    protected $signature = 'launcher:publish-self {version} {exePath} {--notes=}';

    protected $description = 'Публикует новую версию самого лаунчера';

    public function handle(): int
    {
        $version = $this->argument('version');
        $exePath = $this->argument('exePath');
        $notes = $this->option('notes') ?? '';

        if (!is_file($exePath)) {
            $this->error("Файл не найден: {$exePath}");
            return self::FAILURE;
        }

        $fileName = basename($exePath);
        $storedPath = "launcher-self/{$fileName}";

        // Открываем файл как поток, не загружая целиком в RAM
        $stream = fopen($exePath, 'rb');

        if ($stream === false) {
            $this->error('Не удалось открыть EXE-файл.');
            return self::FAILURE;
        }

        try {
            $uploaded = Storage::disk('public')->put($storedPath, $stream);
        } finally {
            fclose($stream);
        }

        if (!$uploaded) {
            $this->error('Не удалось сохранить EXE-файл.');
            return self::FAILURE;
        }

        $state = [
            'version' => $version,
            'exe_url' => Storage::disk('public')->url($storedPath),
            'notes' => $notes,
        ];

        Storage::disk('local')->put(
            'launcher-self-version.json',
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->info("Опубликована версия лаунчера {$version}");
        $this->line("Файл: {$storedPath}");
        $this->line("exe_url: {$state['exe_url']}");

        return self::SUCCESS;
    }
}
