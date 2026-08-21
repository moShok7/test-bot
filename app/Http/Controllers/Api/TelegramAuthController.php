<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telegram\TelegramAuthRequest;
use App\Models\TelegramUser;
use Illuminate\Http\Request;

class TelegramAuthController extends Controller
{
    public function register(TelegramAuthRequest $request){
        $data = $request->validated();
        $user = TelegramUser::updateOrCreate(
        [
            'telegram_id' => $data['telegram_id']
        ],
        [
            'username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? null,
        ]
    );


    return response()->json([
        'success' => true,
        'user' => $user
    ]);
    }
}
