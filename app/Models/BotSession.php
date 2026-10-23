<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSession extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'step',
        'data',
        'temp_game_nickname',
        'temp_game_id',
        'change_lobby_code',
        'temp_clan_name',
        'temp_clan_chat_id',
        'temp_clan_chat_input',
    ];

    public function telegramUser()
    {
        return $this->belongsTo(TelegramUser::class);
    }
}
