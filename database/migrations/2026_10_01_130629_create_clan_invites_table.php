<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_invites', function (Blueprint $table) {
            $table->id();

            $table->foreignId('clan_id')
                ->constrained('clans')
                ->cascadeOnDelete();

            // Уникальный токен для deep-link
            $table->string('token', 100)->unique();

            // Кто создал приглашение
            $table->unsignedBigInteger('created_by');

            // Пока может быть NULL, то есть приглашение бессрочное
            $table->timestamp('expires_at')->nullable();

            // NULL = без ограничения
            $table->unsignedInteger('max_uses')->nullable();

            $table->unsignedInteger('uses')->default(0);

            // active / disabled
            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index(['clan_id', 'status']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_invites');
    }
};