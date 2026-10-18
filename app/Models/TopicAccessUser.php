<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopicAccessUser extends Model
{
    protected $table = 'topic_access_users';

    protected $fillable = [
        'topic_access_topic_id',
        'telegram_user_id',
        'username',
        'first_name',
        'added_by',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(TopicAccessTopic::class, 'topic_access_topic_id');
    }
}
