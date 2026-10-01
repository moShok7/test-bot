<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clans', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // Telegram ID владельца клана
            $table->unsignedBigInteger('creator_id');

            // Сохраняем для отображения, но не используем как идентификатор
            $table->string('creator_username', 255)->nullable();

            // Telegram ID чата клана
            $table->bigInteger('chat_id');

            // Username чата без @
            $table->string('chat_username', 255)->nullable();

            // Готовая ссылка на чат
            $table->string('chat_link', 500)->nullable();

            // Количество участников Telegram-чата
            $table->unsignedInteger('member_count')->default(0);

            // Когда последний раз обновляли member_count
            $table->timestamp('member_count_updated_at')->nullable();

            // active / disabled
            $table->string('status', 20)->default('active');

            $table->timestamps();

            // Один пользователь может создать только один клан
            $table->unique('creator_id');

            // Один Telegram-чат может принадлежать только одному клану
            $table->unique('chat_id');

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clans');
    }
};