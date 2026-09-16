<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotGroup extends Model
{
    protected $fillable = [
        'chat_id',
        'title',
        'type',
        'is_active',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'is_active' => 'boolean',
    ];
}