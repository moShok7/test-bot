<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premier_lobbies', function (Blueprint $table) {
            $table->id();

            // References the internal TelegramUser model ID, not Telegram's numeric ID.
            $table->foreignId('creator_id')
                ->constrained('telegram_users')
                ->cascadeOnDelete();

            $table->string('title', 80);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');

            // published, cancelled, started, completed
            $table->string('status', 20)->default('published');

            $table->unsignedTinyInteger('max_players')->default(12);
            $table->string('game_room_code', 6)->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_at']);
            $table->index(['creator_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premier_lobbies');
    }
};
