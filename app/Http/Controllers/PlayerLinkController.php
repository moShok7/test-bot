<?php

namespace App\Http\Controllers;

use App\Services\Bot\link\PlayerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlayerLinkController extends Controller
{
    public function __construct(
        private readonly PlayerLinkService $playerLinkService
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'regex:/^\d{6}$/'],
            'tfg_player_id' => ['required', 'string', 'max:128', 'regex:/^[a-fA-F0-9-]+$/'],
        ]);

        $code = $validated['code'];
        $tfgPlayerId = $validated['tfg_player_id'];

        $telegramId = $this->playerLinkService->resolveTelegramId($code);

        if (!$telegramId) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired link code',
            ], 404);
        }

        $alreadyLinked = DB::table('telegram_users')
            ->where('tfg_player_id', $tfgPlayerId)
            ->where('telegram_id', '!=', $telegramId)
            ->exists();

        if ($alreadyLinked) {
            return response()->json([
                'success' => false,
                'message' => 'This game account is already linked',
            ], 409);
        }

        $updated = DB::table('telegram_users')
            ->where('telegram_id', $telegramId)
            ->update([
                'tfg_player_id' => $tfgPlayerId,
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram user not found',
            ], 404);
        }

        $this->playerLinkService->consumeCode($code);

        return response()->json([
            'success' => true,
            'message' => 'Player successfully linked',
            'tfg_player_id' => $tfgPlayerId,
        ]);
    }
}