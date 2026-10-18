<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TopicAccessTopic extends Model
{
    protected $table = 'topic_access_topics';

    protected $fillable = [
        'chat_id',
        'message_thread_id',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(TopicAccessUser::class, 'topic_access_topic_id');
    }
}
