<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LobbyApplication extends Model
{
    protected $table = 'lobby_applications';

    protected $fillable = [
        'premier_lobby_id',
        'telegram_user_id',
        'status',
        'answers',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function premierLobby(): BelongsTo
    {
        return $this->belongsTo(PremierLobby::class, 'premier_lobby_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'telegram_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'reviewed_by');
    }
}
