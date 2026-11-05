<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_events', function (Blueprint $table) {
            $table->string('tfg_player_id')->nullable()->after('room_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('room_events', function (Blueprint $table) {
            $table->dropIndex(['tfg_player_id']);
            $table->dropColumn('tfg_player_id');
        });
    }
};