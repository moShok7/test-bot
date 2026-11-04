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
    Schema::table('lobbies', function (Blueprint $table) {
        $table->string('external_room_id')->nullable()->unique();
    });
}

public function down(): void
{
    Schema::table('lobbies', function (Blueprint $table) {
        $table->dropUnique(['external_room_id']);
        $table->dropColumn('external_room_id');
    });
}
};
