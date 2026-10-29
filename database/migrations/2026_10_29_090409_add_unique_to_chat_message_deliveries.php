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
        Schema::table('chat_message_deliveries', function (Blueprint $table) {
            $table->unique(['chat_message_id', 'telegram_user_id'], 'cmd_message_user_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_message_deliveries', function (Blueprint $table) {
            $table->dropUnique('cmd_message_user_unique');
        });
    }
};
