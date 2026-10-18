<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topic_access_topics', function (Blueprint $table) {
            $table->id();

            $table->bigInteger('chat_id');
            $table->bigInteger('message_thread_id');

            $table->timestamps();

            $table->unique(
                ['chat_id', 'message_thread_id'],
                'topic_access_topics_chat_thread_unique'
            );
        });

        Schema::create('topic_access_users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('topic_access_topic_id')
                ->constrained('topic_access_topics')
                ->cascadeOnDelete();

            $table->bigInteger('telegram_user_id');

            $table->string('username')->nullable();
            $table->string('first_name')->nullable();

            $table->bigInteger('added_by');

            $table->timestamps();

            $table->unique(
                ['topic_access_topic_id', 'telegram_user_id'],
                'topic_access_users_topic_user_unique'
            );

            $table->index('telegram_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_access_users');
        Schema::dropIfExists('topic_access_topics');
    }
};