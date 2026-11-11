<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lobby;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Разместить в: app/Http/Controllers/Api/

class PlayerRosterController extends Controller
{
    public function store(Request $request)
    {
        $request->headers->set('Accept', 'application/json');

        // Та же проверка токена, что и в RoomEventController::store().
        $token = $request->header('X-Auth-Token');
        $expectedToken = config('services.room_api.token');

        if (
            !$expectedToken ||
            !$token ||
            !hash_equals($expectedToken, $token)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:40'],
            'event' => ['nullable', 'string', 'max:255'],

            'room_code' => [
                'required',
                'string',
                'size:6',
                'regex:/^[A-Za-z0-9]{6}$/',
            ],

            'room_id' => ['nullable', 'string', 'max:255'],

            'tfg_player_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:128',
                'regex:/^[a-fA-F0-9\-]+$/',
            ],

            'current_players' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:100',
            ],

            'players' => ['required', 'array', 'max:100'],
            'players.*.name' => ['required', 'string', 'max:255'],
            'players.*.character' => ['nullable', 'string', 'max:255'],
        ]);

        $roomCode = strtoupper($validated['room_code']);

        // Та же приоритизация основной записи, что и в RoomEventController.
        $lobby = Lobby::query()
            ->where('game_room_code', $roomCode)
            ->orderByRaw('(creator_id IS NULL) ASC')
            ->orderByRaw('(external_room_id IS NULL) ASC')
            ->orderBy('id')
            ->first();

        if (!$lobby) {
            Log::warning('Ростер пришёл для неизвестного лобби', [
                'room_code' => $roomCode,
                'room_id' => $validated['room_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lobby not found for this room_code. Send action=create to /room-event first.',
                'room_code' => $roomCode,
            ], 404);
        }

        $players = collect($validated['players'])
            ->map(fn ($player) => [
                'name' => (string) $player['name'],
                'character' => (string) ($player['character'] ?? ''),
            ])
            ->values()
            ->all();

        $lobby->roster = $players;
        $lobby->roster_updated_at = now();

        if (
            array_key_exists('current_players', $validated) &&
            $validated['current_players'] !== null
        ) {
            $lobby->current_players = $validated['current_players'];
        } else {
            $lobby->current_players = count($players);
        }

        $lobby->save();

        Log::info('Ростер лобби обновлён', [
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'players_count' => count($players),
        ]);

        return response()->json([
            'success' => true,
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'players_count' => count($players),
        ]);
    }
}
