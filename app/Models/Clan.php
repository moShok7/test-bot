<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'creator_id',
        'creator_username',
        'chat_id',
        'chat_username',
        'chat_link',
        'member_count',
        'member_count_updated_at',
        'status',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'creator_id' => 'integer',
        'member_count' => 'integer',
        'member_count_updated_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function members(): HasMany
    {
        return $this->hasMany(ClanMember::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(ClanInvite::class);
    }

    public function mutes(): HasMany
    {
        return $this->hasMany(ClanMute::class);
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

    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('status', 'disabled');
    }

    public function scopeForCreator(Builder $query, int $userId): Builder
    {
        return $query->where('creator_id', $userId);
    }

    public function scopeForChat(Builder $query, int $chatId): Builder
    {
        return $query->where('chat_id', $chatId);
    }

    public function scopeWithActiveMembers(Builder $query): Builder
    {
        return $query->with([
            'members' => fn ($query) => $query->active(),
        ]);
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

    public function isCreator(int $userId): bool
    {
        return $this->creator_id === $userId;
    }

    public function activeMembersCount(): int
    {
        return $this->members()
            ->active()
            ->count();
    }
}