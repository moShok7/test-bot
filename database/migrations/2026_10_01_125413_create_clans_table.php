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
        Schema::create('clans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_id');
            $table->string('creator_username', 255)->nullable();
            $table->bigInteger('chat_id');
            $table->string('chat_username', 255)->nullable()->nullable();
            $table->string('chat_link', 500)->nullable();
            $table->BigInteger('member_count')->default(1);
            $table->timestamp('member_count_updated_at')->nullable();
            // active / disabled
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['creator_id', 'chat_id']);  
            $table->index('status');


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clans');
    }
};
