<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lobby extends Model
{
    protected $fillable = [
        'clan_id',
        'creator_id',
        'game_room_code',
        'status',
        'started_at',
        'min_players',
        'max_players',

    ];


protected $casts = [
    'started_at' => 'datetime',
];
    public function players()
    {
        return $this->hasMany(
            LobbyPlayer::class
        );
    }

    public function clan()
{
    return $this->belongsTo(Clan::class);
}
    public function creator()
    {
        return $this->belongsTo(
            TelegramUser::class,
            'creator_id'
        );
    }
}
