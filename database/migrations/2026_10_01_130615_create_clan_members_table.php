<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clan_id')
                ->constrained('clans')
                ->cascadeOnDelete();

            // Telegram ID участника
            $table->unsignedBigInteger('user_id');

            // active / left
            $table->string('status', 20)->default('active');

            $table->timestamp('joined_at')->useCurrent();

            $table->timestamps();

            // Один пользователь не может дважды находиться
            // в одном и том же клане.
            $table->unique(['clan_id', 'user_id']);

            $table->index('user_id');
            $table->index(['clan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_members');
    }
};