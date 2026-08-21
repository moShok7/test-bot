<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSession extends Model
{
       protected $fillable = [
        'telegram_user_id',
        'step',
        'temp_game_nickname',
        'temp_game_id',
        'change_lobby_code'
    ];


    public function telegramUser()
    {
        return $this->belongsTo(TelegramUser::class);
    }
}
