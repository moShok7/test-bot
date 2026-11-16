<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class LauncherController extends Controller
{
    /**
     * GET /api/launcher/version
     *
     * Returns the current mod version info for the C# launcher:
     * { "version": "...", "dll_url": "...", "notes": "..." }
     *
     * The state is a small JSON file updated by the
     * `php artisan launcher:publish` command below — no migration needed.
     */
    public function version(): JsonResponse
    {
        if (!Storage::disk('local')->exists('launcher-version.json')) {
            return response()->json([
                'version' => null,
                'dll_url' => null,
                'notes' => 'Версия мода ещё не опубликована.',
            ]);
        }

        $state = json_decode(Storage::disk('local')->get('launcher-version.json'), true);

        return response()->json($state);
    }
}