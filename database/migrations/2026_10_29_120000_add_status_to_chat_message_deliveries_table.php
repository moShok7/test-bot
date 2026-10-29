<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_message_deliveries', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('processing_at')->nullable()->index();
        });

        DB::table('chat_message_deliveries')
            ->where('telegram_message_id', '>', 0)
            ->update(['status' => 'sent']);
    }

    public function down(): void
    {
        Schema::table('chat_message_deliveries', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['processing_at']);
            $table->dropColumn(['status', 'processing_at']);
        });
    }
};
