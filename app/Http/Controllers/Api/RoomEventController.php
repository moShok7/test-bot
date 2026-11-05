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
        // Проверяем секрет мода.
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

        // Проверяем входящие данные.
        $validated = $request->validate([
            'action' => ['nullable', 'string', 'max:30'],

            'status' => [
                'sometimes',
                'nullable',
                'string',
                'in:waiting,started,finished,closed',
            ],

            'room_code' => [
                'required',
                'string',
                'size:6',
                'regex:/^[A-Za-z0-9]{6}$/',
            ],

            'room_id' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'string', 'max:255'],
            'lobby_type' => ['nullable', 'string', 'max:255'],

            'current_players' => [
                'sometimes',
                'nullable',
                'integer',
                'min:0',
                'max:100',
            ],

            'map_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'game_mode' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $roomCode = strtoupper($validated['room_code']);

        // Ищем лобби по коду комнаты.
        $lobby = Lobby::query()
            ->where('game_room_code', $roomCode)
            ->first();

        if (!$lobby) {
            Log::warning('Игровое лобби не найдено', [
                'room_code' => $roomCode,
                'room_id' => $validated['room_id'] ?? null,
                'action' => $validated['action'] ?? null,
                'status' => $validated['status'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lobby not found',
                'room_code' => $roomCode,
            ], 404);
        }

        // Сохраняем событие в историю.
        RoomEvent::create([
            'action' => $validated['action'] ?? null,
            'status' => $validated['status'] ?? null,
            'room_id' => $validated['room_id'] ?? null,
            'address' => $validated['address'] ?? null,
            'port' => $validated['port'] ?? null,
            'lobby_type' => $validated['lobby_type'] ?? null,
            'map_name' => $validated['map_name'] ?? null,
            'game_mode' => $validated['game_mode'] ?? null,
            'current_players' => $validated['current_players'] ?? null,
        ]);

        // Обновляем только реально переданные поля.
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

        // Обновляем статус лобби.
        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            $changes['status'] = $validated['status'];
        }

        if ($changes === []) {
            return response()->json([
                'success' => true,
                'message' => 'No fields to update',
                'lobby_id' => $lobby->id,
                'room_code' => $roomCode,
            ]);
        }

        // Не разрешаем возвращать закрытую комнату в активное состояние
        // случайным запоздавшим событием.
        $terminalStatuses = ['finished', 'closed'];

        if (
            in_array($lobby->status, $terminalStatuses, true) &&
            isset($changes['status']) &&
            !in_array($changes['status'], $terminalStatuses, true)
        ) {
            unset($changes['status']);
        }

        $lobby->fill($changes);

        if (!$lobby->isDirty()) {
            return response()->json([
                'success' => true,
                'message' => 'No changes',
                'lobby_id' => $lobby->id,
                'room_code' => $roomCode,
            ]);
        }

        $lobby->save();

        // Обновляем сообщения Telegram.
        UpdateLobbyTelegramNotificationsJob::dispatch($lobby->id)
            ->onQueue('telegram');

        Log::info('Игровые данные лобби обновлены', [
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'room_id' => $validated['room_id'] ?? null,
            'changes' => $changes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lobby updated',
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'updated' => $changes,
        ]);
    }
}
