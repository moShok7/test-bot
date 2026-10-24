<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUserMute extends Model
{
    protected $fillable = [
        'chat_id',
        'user_id',
        'muted_by',
        'muted_until',
    ];

    protected function casts(): array
    {
        return [
            'muted_until' => 'datetime',
        ];
    }
}