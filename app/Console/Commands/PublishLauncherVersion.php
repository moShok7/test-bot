<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishLauncherVersion extends Command
{
    /**
     * Usage:
     *   php artisan launcher:publish 1.5.0 /path/to/SafeMod.dll --notes="фикс CurrentRoomCode"
     */
    protected $signature = 'launcher:publish {version} {dllPath} {--notes=}';

    protected $description = 'Publish a new mod version for the launcher auto-update check';

    public function handle(): int
    {
        $version = $this->argument('version');
        $dllPath = $this->argument('dllPath');
        $notes = $this->option('notes') ?? '';

        if (!is_file($dllPath)) {
            $this->error("Файл не найден: {$dllPath}");
            return self::FAILURE;
        }

        $fileName = basename($dllPath);
        $storedPath = "launcher/{$fileName}";

        Storage::disk('public')->put($storedPath, file_get_contents($dllPath));

        $state = [
            'version' => $version,
            'dll_url' => Storage::disk('public')->url($storedPath),
            'notes' => $notes,
        ];

        Storage::disk('local')->put('launcher-version.json', json_encode($state, JSON_PRETTY_PRINT));

        $this->info("Опубликована версия {$version} -> {$storedPath}");
        $this->line("dll_url: {$state['dll_url']}");

        return self::SUCCESS;
    }
}