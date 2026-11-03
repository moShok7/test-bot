<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_events', function (Blueprint $table) {
            $table->id();
            $table->string('action', 30);
            $table->string('room_id')->nullable()->index();
            $table->string('address')->nullable();
            $table->string('port')->nullable();
            $table->string('lobby_type')->nullable();
            $table->string('map_name')->nullable();
            $table->string('game_mode')->nullable();
            $table->unsignedTinyInteger('current_players')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_events');
    }
};