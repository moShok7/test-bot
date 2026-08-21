<?php

use Illuminate\Http\Request;
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
Route::post('/telegram/register', [TelegramAuthController::class, 'register']);