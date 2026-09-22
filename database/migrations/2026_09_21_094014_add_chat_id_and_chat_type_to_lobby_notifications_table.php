<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lobby_notifications', function (Blueprint $table) {
            
            $table->string('chat_id')->nullable()->after('telegram_user_id');
            $table->string('chat_type')->nullable()->after('chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('lobby_notifications', function (Blueprint $table) {
            $table->dropColumn([
                'chat_id',
                'chat_type',
            ]);
        });
    }
};
