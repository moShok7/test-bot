<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'clan_id',
        'token',
        'created_by',
        'expires_at',
        'max_uses',
        'uses',
        'status',
    ];

    protected $casts = [
        'clan_id' => 'integer',
        'created_by' => 'integer',
        'expires_at' => 'datetime',
        'max_uses' => 'integer',
        'uses' => 'integer',
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

    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('status', 'disabled');
    }

    public function scopeByToken(Builder $query, string $token): Builder
    {
        return $query->where('token', $token);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where(function (Builder $query) {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where(function (Builder $query) {
                $query
                    ->whereNull('max_uses')
                    ->orWhereColumn('uses', '<', 'max_uses');
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isExpired(): bool
    {
        return $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function hasReachedLimit(): bool
    {
        return $this->max_uses !== null
            && $this->uses >= $this->max_uses;
    }

    public function isValid(): bool
    {
        return $this->status === 'active'
            && !$this->isExpired()
            && !$this->hasReachedLimit();
    }
}