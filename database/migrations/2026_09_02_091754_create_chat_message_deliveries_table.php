<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_message_deliveries', function (Blueprint $table) {
    $table->id();

    $table->foreignId('chat_message_id')
        ->constrained('chat_messages')
        ->cascadeOnDelete();

    $table->foreignId('telegram_user_id')
        ->constrained('telegram_users')
        ->cascadeOnDelete();

    $table->bigInteger('telegram_message_id');

    $table->timestamps();

    $table->unique([
        'chat_message_id',
        'telegram_user_id',
    ]);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_message_deliveries');
    }
};
