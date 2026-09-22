<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LobbyNotification extends Model
{
    protected $fillable = [
        'lobby_id',
        'telegram_user_id',
        'telegram_message_id',
        'chat_id',
        'chat_type',
    ];

    public function telegramUser()
    {
        return $this->belongsTo(
            TelegramUser::class,
            'telegram_user_id'
        );
    }

    public function lobby()
    {
        return $this->belongsTo(
            Lobby::class,
            'lobby_id'
        );
    }
}
