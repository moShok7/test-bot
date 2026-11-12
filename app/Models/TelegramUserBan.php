<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUserBan extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'banned_by',
        'reason',
    ];

    public function telegramUser()
    {
        return $this->belongsTo(
            TelegramUser::class,
            'telegram_user_id'
        );
    }
}
