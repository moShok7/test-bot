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
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->string('temp_clan_name', 100)
                ->nullable()
                ->after('change_lobby_code');

            $table->bigInteger('temp_clan_chat_id')
                ->nullable()
                ->after('temp_clan_name');

            $table->string('temp_clan_chat_input', 500)
                ->nullable()
                ->after('temp_clan_chat_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'temp_clan_name',
                'temp_clan_chat_id',
                'temp_clan_chat_input',
            ]);
        });
    }
};