<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateLobbyTelegramNotificationsJob;
use App\Models\Lobby;
use App\Models\RoomEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $action = $validated['action'] ?? null;

        $lobby = null;
        $created = false;
        $changes = [];

        DB::transaction(function () use (
            $validated,
            $roomCode,
            $action,
            &$lobby,
            &$created,
            &$changes
        ) {
            // Ищем существующее лобби.
            $lobby = Lobby::query()
                ->where('game_room_code', $roomCode)
                ->lockForUpdate()
                ->first();

            // Если лобби отсутствует, разрешаем создать его
            // только при получении события create.
            if (!$lobby) {
                if ($action !== 'create') {
                    return;
                }

                $lobby = Lobby::create([
                    'creator_id' => null,
                    'clan_id' => null,
                    'game_room_code' => $roomCode,
                    'external_room_id' => $validated['room_id'] ?? null,
                    'status' => $validated['status'] ?? 'waiting',
                    'current_players' => $validated['current_players'] ?? 1,
                    'map_name' => $validated['map_name'] ?? null,
                    'game_mode' => $validated['game_mode'] ?? null,
                ]);

                $created = true;
            } else {
                // Обновляем только реально переданные поля.
                $fields = [
                    'current_players',
                    'map_name',
                    'game_mode',
                ];

                foreach ($fields as $field) {
                    if (array_key_exists($field, $validated)) {
                        $changes[$field] = $validated[$field];
                    }
                }

                // Сохраняем внешний ID игровой комнаты.
                if (
                    array_key_exists('room_id', $validated) &&
                    $validated['room_id'] !== null
                ) {
                    $changes['external_room_id'] = $validated['room_id'];
                }

                // Обновляем статус.
                if (
                    array_key_exists('status', $validated) &&
                    $validated['status'] !== null
                ) {
                    $changes['status'] = $validated['status'];
                }

                // Не разрешаем запоздавшим событиям
                // возвращать завершённую комнату в активное состояние.
                $terminalStatuses = ['finished', 'closed'];

                if (
                    in_array($lobby->status, $terminalStatuses, true) &&
                    isset($changes['status']) &&
                    !in_array($changes['status'], $terminalStatuses, true)
                ) {
                    unset($changes['status']);
                }

                $lobby->fill($changes);

                if ($lobby->isDirty()) {
                    $lobby->save();
                }
            }

            // Сохраняем событие в историю.
            RoomEvent::create([
                'action' => $action,
                'status' => $validated['status'] ?? null,
                'room_id' => $validated['room_id'] ?? null,
                'address' => $validated['address'] ?? null,
                'port' => $validated['port'] ?? null,
                'lobby_type' => $validated['lobby_type'] ?? null,
                'map_name' => $validated['map_name'] ?? null,
                'game_mode' => $validated['game_mode'] ?? null,
                'current_players' => $validated['current_players'] ?? null,
            ]);
        });

        // Если лобби не найдено и событие не create.
        if (!$lobby) {
            Log::warning('Игровое лобби не найдено', [
                'room_code' => $roomCode,
                'room_id' => $validated['room_id'] ?? null,
                'action' => $action,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lobby not found. Send action=create first.',
                'room_code' => $roomCode,
            ], 404);
        }

        // Обновляем Telegram только для лобби с привязанным кланом.
        if ($lobby->clan_id !== null && ($created || $lobby->wasChanged() || $changes !== [])) {
            UpdateLobbyTelegramNotificationsJob::dispatch($lobby->id)
                ->onQueue('telegram');
        }

        Log::info(
            $created
                ? 'Игровое лобби создано'
                : 'Игровые данные лобби обновлены',
            [
                'lobby_id' => $lobby->id,
                'room_code' => $roomCode,
                'room_id' => $validated['room_id'] ?? null,
                'created' => $created,
                'changes' => $changes,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => $created ? 'Lobby created' : 'Lobby updated',
            'created' => $created,
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'updated' => $changes,
        ]);
    }
}
