<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\LobbyPlayer;
use App\Models\ChatMessage;

class TelegramUser extends Model
{
    protected $fillable = [
        'telegram_id',
        'username',
        'first_name'
    ];
    public function gameProfile()
    {
        return $this->hasOne(GameProfile::class);
    }
    public function botSession()
    {
        return $this->hasOne(BotSession::class);
    }
    public function lobbies()
{
    return $this->hasMany(
        LobbyPlayer::class
    );
}
public function lobbyPlayers()
{
    return $this->hasMany(
        LobbyPlayer::class,
        'telegram_user_id'
    );
}
public function chatMessages()
{
    return $this->hasMany(ChatMessage::class, 'telegram_user_id');
}
}
