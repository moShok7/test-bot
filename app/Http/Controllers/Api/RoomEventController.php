<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendLobbyNotificationsJob;
use App\Jobs\UpdateLobbyTelegramNotificationsJob;
use App\Models\Lobby;
use App\Models\LobbyNotification;
use App\Models\LobbyPlayer;
use App\Models\RoomEvent;
use App\Models\TelegramUser;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomEventController extends Controller
{
    public function store(Request $request)
    {
        // Проверка секретного токена мода.
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

        // Проверка входящих данных.
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

            'map_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'game_mode' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $roomCode = strtoupper($validated['room_code']);
        $action = $validated['action'] ?? null;

        $lobby = null;
        $created = false;
        $merged = false;
        $shouldNotify = false;
        $changes = [];

        // Заполняются внутри транзакции, если у лобби появился создатель
        // (при создании или бэкфиллом) и ему ещё не отправляли первую рассылку.
        $resolvedCreator = null;
        $creatorJustResolved = false;

        try {
            DB::transaction(function () use (
                $validated,
                $roomCode,
                $action,
                &$lobby,
                &$created,
                &$merged,
                &$shouldNotify,
                &$changes,
                &$resolvedCreator,
                &$creatorJustResolved
            ) {
                /*
                 * Получаем ВСЕ записи с таким коротким кодом.
                 * Основная запись выбирается с приоритетом:
                 * 1. creator_id заполнен.
                 * 2. external_room_id заполнен.
                 */
                $lobbies = Lobby::query()
                    ->where('game_room_code', $roomCode)
                    ->orderByRaw('(creator_id IS NULL) ASC')
                    ->orderByRaw('(external_room_id IS NULL) ASC')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                // Пытаемся резолвить создателя по tfg_player_id (если игрок привязал Telegram).
                if (!empty($validated['tfg_player_id'])) {
                    $resolvedCreator = TelegramUser::query()
                        ->where('tfg_player_id', $validated['tfg_player_id'])
                        ->first();
                }

                $resolvedCreatorId = $resolvedCreator?->id;

                // Если лобби ещё нет, создаём его.
                if ($lobbies->isEmpty()) {
                    if ($action !== 'create') {
                        return;
                    }

                    $lobby = Lobby::create([
                        'creator_id' => $resolvedCreatorId,
                        'clan_id' => null,
                        'game_room_code' => $roomCode,
                        'external_room_id' => $validated['room_id'] ?? null,
                        'status' => $validated['status'] ?? 'waiting',
                        'current_players' => $validated['current_players'] ?? 1,
                        'map_name' => $validated['map_name'] ?? null,
                        'game_mode' => $validated['game_mode'] ?? null,
                    ]);

                    $created = true;
                    $shouldNotify = true;

                    if ($resolvedCreatorId) {
                        $creatorJustResolved = true;
                    }

                    $changes = [
                        'creator_id' => $lobby->creator_id,
                        'external_room_id' => $lobby->external_room_id,
                        'current_players' => $lobby->current_players,
                        'map_name' => $lobby->map_name,
                        'game_mode' => $lobby->game_mode,
                        'status' => $lobby->status,
                    ];
                } else {
                    // Первая запись после сортировки становится основной.
                    $lobby = $lobbies->first();

                    // Объединяем все остальные записи.
                    foreach ($lobbies->skip(1) as $duplicate) {
                        $this->mergeDuplicateLobby($lobby, $duplicate);

                        $merged = true;
                        $shouldNotify = true;
                    }

                    // Обновляем игровые данные основного лобби.
                    $updates = [];

                    $fields = [
                        'current_players',
                        'map_name',
                        'game_mode',
                    ];

                    foreach ($fields as $field) {
                        if (
                            array_key_exists($field, $validated) &&
                            $validated[$field] !== null
                        ) {
                            $updates[$field] = $validated[$field];
                        }
                    }

                    // Внешний ID игровой комнаты.
                    if (!empty($validated['room_id'])) {
                        $updates['external_room_id'] = $validated['room_id'];
                    }

                    // Статус комнаты.
                    if (
                        array_key_exists('status', $validated) &&
                        $validated['status'] !== null
                    ) {
                        $updates['status'] = $validated['status'];
                    }

                    // Завершённую комнату нельзя вернуть в waiting или started.
                    $terminalStatuses = ['finished', 'closed'];

                    if (
                        in_array($lobby->status, $terminalStatuses, true) &&
                        isset($updates['status']) &&
                        !in_array($updates['status'], $terminalStatuses, true)
                    ) {
                        unset($updates['status']);
                    }

                    // Бэкфилл creator_id: лобби могло быть создано до того,
                    // как мод узнал/отправил TFGPlayerId (или до линковки в Telegram).
                    if (empty($lobby->creator_id) && $resolvedCreatorId) {
                        $updates['creator_id'] = $resolvedCreatorId;
                        $creatorJustResolved = true;
                    }

                    $lobby->fill($updates);

                    if ($lobby->isDirty()) {
                        $lobby->save();
                        $shouldNotify = true;

                        $changes = $lobby->getChanges();

                        unset(
                            $changes['updated_at'],
                            $changes['created_at']
                        );
                    }
                }

                // Если создатель только что определился — добавляем его как игрока лобби.
                if ($creatorJustResolved && $resolvedCreatorId) {
                    LobbyPlayer::firstOrCreate([
                        'lobby_id' => $lobby->id,
                        'telegram_user_id' => $resolvedCreatorId,
                    ]);
                }

                // Записываем историю события.
                if ($lobby) {
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
                        'tfg_player_id' => $validated['tfg_player_id'] ?? null,
                    ]);
                }
            }, 3);
        } catch (QueryException $e) {
            Log::error('Ошибка обработки игрового лобби', [
                'room_code' => $roomCode,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Лобби не найдено и не было создано.
        if (!$lobby) {
            Log::warning('Игровое лобби не найдено', [
                'room_code' => $roomCode,
                'action' => $action,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Lobby not found. Send action=create first.',
                'room_code' => $roomCode,
            ], 404);
        }

        /*
         * Если создатель только что определился (при создании или бэкфиллом) и
         * первая рассылка по этому лобби ещё не запускалась — запускаем её,
         * как это делает CreateLobbyHandler для лобби, созданных через бота.
         * Иначе — обычный Update-джоб, который редактирует уже отправленные сообщения.
         */
        $initialNotificationSent = false;

        if ($creatorJustResolved && $resolvedCreator) {
            $alreadyNotified = LobbyNotification::where('lobby_id', $lobby->id)->exists();

            if (!$alreadyNotified) {
                $creatorName = $resolvedCreator->username
                    ? '@' . $resolvedCreator->username
                    : ($resolvedCreator->first_name ?: 'Игрок');

                SendLobbyNotificationsJob::dispatch(
                    lobbyId: (int) $lobby->id,
                    creatorTelegramId: (int) $resolvedCreator->telegram_id,
                    creatorName: (string) $creatorName,
                    count: 100,
                )->onQueue('telegram');

                $initialNotificationSent = true;
            }
        }

        if ($shouldNotify && !$initialNotificationSent) {
            UpdateLobbyTelegramNotificationsJob::dispatch($lobby->id)
                ->onQueue('telegram');
        }

        Log::info('Игровое лобби обработано', [
            'lobby_id' => $lobby->id,
            'room_code' => $roomCode,
            'created' => $created,
            'merged_duplicates' => $merged,
            'creator_id' => $lobby->creator_id,
            'external_room_id' => $lobby->external_room_id,
            'initial_notification_sent' => $initialNotificationSent,
            'changes' => $changes,
        ]);

        return response()->json([
            'success' => true,
            'message' => $created
                ? 'Lobby created'
                : ($merged ? 'Lobby merged and updated' : 'Lobby updated'),

            'created' => $created,
            'merged' => $merged,
            'lobby_id' => $lobby->id,
            'creator_id' => $lobby->creator_id,
            'room_code' => $roomCode,
            'updated' => $changes,
        ]);
    }

    /**
     * Переносит данные дубликата в основное лобби.
     */
    private function mergeDuplicateLobby(
        Lobby $target,
        Lobby $source
    ): void {
        $sourceId = $source->id;

        // Переносим игровые поля, если в основной записи они пустые.
        $fields = [
            'current_players',
            'map_name',
            'game_mode',
            'started_at',
            'min_players',
            'max_players',
        ];

        foreach ($fields as $field) {
            if (
                ($target->{$field} === null || $target->{$field} === '') &&
                $source->{$field} !== null &&
                $source->{$field} !== ''
            ) {
                $target->{$field} = $source->{$field};
            }
        }

        /*
         * Если у основной записи нет external_room_id,
         * забираем его из дубликата.
         *
         * Сначала освобождаем ID в исходной записи,
         * если в БД на него установлен уникальный индекс.
         */
        if (
            empty($target->external_room_id) &&
            !empty($source->external_room_id)
        ) {
            $roomId = $source->external_room_id;

            $source->external_room_id = null;
            $source->save();

            $target->external_room_id = $roomId;
        }

        // Если у основной записи нет creator_id, забираем из дубликата.
        if (empty($target->creator_id) && !empty($source->creator_id)) {
            $target->creator_id = $source->creator_id;
        }

        // Сохраняем более актуальный статус.
        $terminalStatuses = ['finished', 'closed'];

        if (
            in_array($source->status, $terminalStatuses, true) ||
            (
                $target->status === 'waiting' &&
                $source->status !== 'waiting'
            )
        ) {
            $target->status = $source->status;
        }

        $target->save();

        // Переносим Telegram-сообщения на основную запись.
        LobbyNotification::query()
            ->where('lobby_id', $sourceId)
            ->update([
                'lobby_id' => $target->id,
            ]);

        // Удаляем старый дубликат.
        $source->delete();

        Log::warning('Дубликат игрового лобби объединён', [
            'main_lobby_id' => $target->id,
            'deleted_lobby_id' => $sourceId,
            'room_code' => $target->game_room_code,
            'creator_id' => $target->creator_id,
            'external_room_id' => $target->external_room_id,
        ]);
    }
}