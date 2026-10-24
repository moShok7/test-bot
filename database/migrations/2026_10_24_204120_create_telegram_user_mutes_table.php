<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telegram_user_mutes', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->bigInteger('user_id');
            $table->bigInteger('muted_by')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_user_mutes');
    }
};