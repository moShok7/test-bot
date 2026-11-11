<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Разместить в: database/migrations/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lobbies', function (Blueprint $table) {
            $table->json('roster')->nullable()->after('current_players');
            $table->timestamp('roster_updated_at')->nullable()->after('roster');
        });
    }

    public function down(): void
    {
        Schema::table('lobbies', function (Blueprint $table) {
            $table->dropColumn(['roster', 'roster_updated_at']);
        });
    }
};
