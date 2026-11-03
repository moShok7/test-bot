<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lobbies', function (Blueprint $table) {
            $table->unsignedTinyInteger('current_players')->nullable()->after('max_players');
            $table->string('map_name')->nullable()->after('current_players');
            $table->string('game_mode')->nullable()->after('map_name');
        }); 
    }

    public function down(): void
    {
        Schema::table('lobbies', function (Blueprint $table) {
            $table->dropColumn(['current_players', 'map_name', 'game_mode']);
        });
    }
};