<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Разместить в: database/migrations/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lobby_players', function (Blueprint $table) {
            $table->string('nickname')->nullable()->after('telegram_user_id');
            $table->string('character')->nullable()->after('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('lobby_players', function (Blueprint $table) {
            $table->dropColumn(['nickname', 'character']);
        });
    }
};
