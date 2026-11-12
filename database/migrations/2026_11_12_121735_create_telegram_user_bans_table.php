<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_user_bans', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('telegram_user_id')->unique();

            $table->unsignedBigInteger('banned_by');

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->foreign('telegram_user_id')
                ->references('id')
                ->on('telegram_users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_user_bans');
    }
};
