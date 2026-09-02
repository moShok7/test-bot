<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessageDelivery extends Model
{
    protected $fillable = [
        'chat_message_id',
        'telegram_user_id',
        'telegram_message_id',
    ];

    public function message()
    {
        return $this->belongsTo(
            ChatMessage::class,
            'chat_message_id'
        );
    }

    public function user()
    {
        return $this->belongsTo(
            TelegramUser::class,
            'telegram_user_id'
        );
    }
}