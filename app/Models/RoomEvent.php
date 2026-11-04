<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomEvent extends Model
{
    protected $fillable = [
        'action',
        'room_id',
        'address',
        'port',
        'lobby_type',
        'map_name',
        'game_mode',
        'current_players',
    ];
}