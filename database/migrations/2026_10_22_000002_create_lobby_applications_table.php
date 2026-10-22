<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lobby_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('premier_lobby_id')
                ->constrained('premier_lobbies')
                ->cascadeOnDelete();

            $table->foreignId('telegram_user_id')
                ->constrained('telegram_users')
                ->cascadeOnDelete();

            // pending, approved, rejected
            $table->string('status', 20)->default('pending');

            // Flexible answers to the application questionnaire.
            $table->json('answers')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('telegram_users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // A user can have only one application per premier lobby.
            $table->unique(
                ['premier_lobby_id', 'telegram_user_id'],
                'lobby_applications_lobby_user_unique'
            );

            $table->index(['premier_lobby_id', 'status']);
            $table->index(['telegram_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lobby_applications');
    }
};
