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
        Schema::create('game_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')
    ->constrained('telegram_users')->unique();
        $table->string('game_nickname');
    $table->string('game_id');
    $table->string('screenshot')
        ->nullable();
    $table->boolean('verified')
        ->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_profiles');
    }
};
