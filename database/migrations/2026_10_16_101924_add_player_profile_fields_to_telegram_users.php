
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->string('game_skin')->nullable();
            $table->text('bio')->nullable();
            $table->string('age_category')->nullable();
            $table->string('profile_editing')->nullable();
            $table->string('profile_social_platform')->nullable();
        });

        Schema::create('telegram_user_socials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('telegram_user_id')
                ->constrained('telegram_users')
                ->cascadeOnDelete();

            $table->string('platform', 50);
            $table->string('url', 2048);

            $table->timestamps();

            $table->unique(['telegram_user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_user_socials');

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn([
                'game_skin',
                'bio',
                'age_category',
                'profile_editing',
                'profile_social_platform',
            ]);
        });
    }
};
