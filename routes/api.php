<?php

use App\Http\Controllers\Api\RoomEventController;
use Illuminate\Http\Request;
use App\Http\Controllers\PlayerLinkController;
use App\Http\Controllers\Api\TelegramAuthController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post(
    '/telegram/webhook',
    [TelegramWebhookController::class,'handle']
);
// routes/api.php
Route::post('/player-link', [PlayerLinkController::class, 'store'])
    ->middleware('auth.token'); // тот же мидлвар, что проверяет X-Auth-Token у /api/room-event
Route::post('/room-event', [RoomEventController::class, 'store']);
Route::post('/telegram/register', [TelegramAuthController::class, 'register']);