<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateLobbyTelegramNotificationsJob;
use App\Models\Lobby;
use App\Models\RoomEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RoomEventController extends Controller
{
    public function store(Request $request)
    {
        // Проверяем токен мода.
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
            'action' => ['nullable', 'string', 'max:30'],
            'room_id' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'string', 'max:255'],
            'lobby_type' => ['nullable', 'string', 'max:255'],
            'current_players' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'map_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'game_mode' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Пишем сырой ивент в историю — независимо от того, найдётся лобби или нет.
        RoomEvent::create([
            'action' => $validated['action'] ?? null,
            'room_id' => $validated['room_id'],
            'address' => $validated['address'] ?? null,
            'port' => $validated['port'] ?? null,
            'lobby_type' => $validated['lobby_type'] ?? null,
            'map_name' => $validated['map_name'] ?? null,
            'game_mode' => $validated['game_mode'] ?? null,
            'current_players' => $validated['current_players'] ?? null,
        ]);

        // Ищем уже существующее лобби по игровому коду.
        $lobby = Lobby::query()
            ->where('game_room_code', $validated['room_id'])
            ->first();

        if (!$lobby) {
            Log::warning('Игровое лобби не найдено', [
                'room_id' => $validated['room_id'],
                'action' => $validated['action'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lobby not found',
            ], 404);
        }

        // Не обновляем закрытые лобби.
        if (!in_array($lobby->status, ['waiting', 'playing'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Lobby is not active',
            ], 409);
        }

        $fields = [
            'current_players',
            'map_name',
            'game_mode',
        ];

        $changes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        if ($changes === []) {
            return response()->json([
                'success' => true,
                'message' => 'No fields to update',
                'lobby_id' => $lobby->id,
            ]);
        }

        $lobby->fill($changes);

        if (!$lobby->isDirty()) {
            return response()->json([
                'success' => true,
                'message' => 'No changes',
                'lobby_id' => $lobby->id,
            ]);
        }

        $lobby->save();

        // Обновляем уже отправленные сообщения Telegram.
        UpdateLobbyTelegramNotificationsJob::dispatch($lobby->id)
            ->onQueue('telegram');

        Log::info('Игровые данные лобби обновлены', [
            'lobby_id' => $lobby->id,
            'room_code' => $lobby->game_room_code,
            'changes' => $changes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lobby updated',
            'lobby_id' => $lobby->id,
            'updated' => $changes,
        ]);
    }
}