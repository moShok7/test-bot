<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LobbyPlayer extends Model
{
      protected $fillable = [

        'lobby_id',
        'telegram_user_id',
        'ready'

    ];


public function lobby()
{
    return $this->belongsTo(
        Lobby::class
    );
}
   public function telegramUser()
{
    return $this->belongsTo(
        TelegramUser::class,
        'telegram_user_id'
    );
}
}
