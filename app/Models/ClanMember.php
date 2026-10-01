<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_id',
        'user_id',
        'status',
        'joined_at',
    ];

    protected $casts = [
        'clan_id' => 'integer',
        'user_id' => 'integer',
        'joined_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeLeft(Builder $query): Builder
    {
        return $query->where('status', 'left');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForClan(Builder $query, int $clanId): Builder
    {
        return $query->where('clan_id', $clanId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLeft(): bool
    {
        return $this->status === 'left';
    }
}