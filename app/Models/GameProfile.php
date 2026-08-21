<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameProfile extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'game_nickname',
        'game_id',
        'screenshot',
        'verified'
    ];
    public function telegramUser()
    {
        return $this->belongsTo(TelegramUser::class);
    }
}
