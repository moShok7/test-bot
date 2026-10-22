<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PremierLobby extends Model
{
    protected $table = 'premier_lobbies';

    protected $fillable = [
        'creator_id',
        'title',
        'description',
        'starts_at',
        'status',
        'max_players',
        'game_room_code',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'max_players' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'creator_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LobbyApplication::class, 'premier_lobby_id');
    }

    public function pendingApplications(): HasMany
    {
        return $this->applications()->where('status', 'pending');
    }

    public function approvedApplications(): HasMany
    {
        return $this->applications()->where('status', 'approved');
    }

    public function getApprovedPlayersCountAttribute(): int
    {
        return (int) $this->approvedApplications()->count();
    }

    public function hasAvailableSlots(): bool
    {
        // Creator occupies one of the available places.
        return $this->approvedApplications()->count() < max(0, $this->max_players - 1);
    }
}
