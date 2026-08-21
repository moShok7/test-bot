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
        Schema::create('lobby_players', function (Blueprint $table) {
            $table->id();
              $table->foreignId('lobby_id')
        ->constrained()
        ->cascadeOnDelete();


    $table->foreignId('telegram_user_id')
        ->constrained()
        ->cascadeOnDelete();


    // готов или нет
    $table->boolean('ready')
        ->default(false);


    // когда вошёл
    $table->timestamps();


    // один игрок не может быть два раза
    $table->unique([
        'lobby_id',
        'telegram_user_id'
    ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lobby_players');
    }
};
