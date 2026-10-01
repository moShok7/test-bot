<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_mutes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clan_id')
                ->constrained('clans')
                ->cascadeOnDelete();

            // Telegram ID замьюченного пользователя
            $table->unsignedBigInteger('user_id');

            // Telegram ID главы, который выдал мут
            $table->unsignedBigInteger('muted_by');

            // До какого момента действует мут
            $table->timestamp('expires_at');

            $table->timestamps();

            // У пользователя может быть только один
            // текущий мут в конкретном клане.
            $table->unique(['clan_id', 'user_id']);

            $table->index('user_id');
            $table->index('muted_by');
            $table->index('expires_at');
            $table->index(['clan_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_mutes');
    }
};