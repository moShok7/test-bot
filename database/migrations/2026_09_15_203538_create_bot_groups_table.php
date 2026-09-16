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
        Schema::create('bot_groups', function (Blueprint $table) {
            $table->id();
             $table->bigInteger('chat_id')->unique();

        $table->string('title')->nullable();

        $table->string('type')->default('supergroup');

        $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_groups');
    }
};
