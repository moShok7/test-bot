<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishLauncherSelf extends Command
{
    /**
     * Использование:
     *   php artisan launcher:publish-self 1.1.0 /tmp/SuspectsLauncher.exe --notes="правки UI"
     */
    protected $signature = 'launcher:publish-self {version} {exePath} {--notes=}';

    protected $description = 'Публикует новую версию самого лаунчера (exe) для самообновления';

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

        Storage::disk('public')->put($storedPath, file_get_contents($exePath));

        $state = [
            'version' => $version,
            'exe_url' => Storage::disk('public')->url($storedPath),
            'notes' => $notes,
        ];

        Storage::disk('local')->put('launcher-self-version.json', json_encode($state, JSON_PRETTY_PRINT));

        $this->info("Опубликована версия лаунчера {$version} -> {$storedPath}");
        $this->line("exe_url: {$state['exe_url']}");

        return self::SUCCESS;
    }
}