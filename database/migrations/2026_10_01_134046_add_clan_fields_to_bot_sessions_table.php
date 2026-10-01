<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_sessions', function (Blueprint $table) {
            $table->string('temp_clan_name', 100)->nullable();
            $table->bigInteger('temp_clan_chat_id')->nullable();
            $table->string('temp_clan_chat_input', 500)->nullable();
        });
    }

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