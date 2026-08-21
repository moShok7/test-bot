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
      Schema::create('lobbies', function (Blueprint $table) {

    $table->id();


    // кто создал лобби в боте
    $table->foreignId('creator_id')
        ->constrained('telegram_users')
        ->cascadeOnDelete();


    // код комнаты из игры
    $table->string('game_room_code');


    // waiting_code / waiting_players / playing
    $table->string('status')
        ->default('waiting');


    // минимум и максимум игроков
    $table->integer('min_players')
        ->default(4);


    $table->integer('max_players')
        ->default(12);


    $table->timestamps();

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lobbies');
    }
};
