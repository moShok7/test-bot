<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lobby_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('lobby_notifications', function (Blueprint $table) {
            $table->dropColumn(['chat_id', 'chat_type']);

            $table->unsignedBigInteger('telegram_user_id')
                ->nullable(false)
                ->change();
        });
    }
};
