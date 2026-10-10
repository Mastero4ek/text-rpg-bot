<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('tg_id')->primary();
            $table->string('username')->nullable();
            $table->foreignId('birth_city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->restrictOnDelete();
            $table->string('progress_step')->default(ProgressStepEnum::SPLASH->value);
            $table->boolean('onboarding_skipped')->default(false);
            $table->unsignedInteger('level')->default(0);
            $table->unsignedInteger('exp')->default(0);
            $table->unsignedInteger('silver')->default(20);
            $table->unsignedInteger('gold')->default(0);
            $table->integer('strength')->default(3);
            $table->integer('agility')->default(3);
            $table->integer('instinct')->default(3);
            $table->integer('vitality')->default(3);
            $table->integer('current_hp');
            $table->unsignedInteger('max_hp');
            $table->timestamp('last_hp_update');
            $table->unsignedInteger('current_stamina');
            $table->unsignedInteger('max_stamina');
            $table->timestamp('last_stamina_update');
            $table->timestamp('last_action_at')->nullable();
            $table->integer('stat_points')->default(3);
            $table->unsignedInteger('bag_max_rows')->default(10);
            $table->unsignedInteger('backpack_max_rows')->default(50);
            $table->unsignedInteger('arena_points')->default(0);
            $table->timestamp('premium_until')->nullable();
            $table->timestamp('banned_until')->nullable();
            $table->unsignedBigInteger('tg_chat_id')->nullable();
            $table->unsignedBigInteger('tg_message_id')->nullable();
            $table->json('tg_pending_delete_ids')->nullable();
            $table->string('fight_return')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            'CREATE UNIQUE INDEX characters_username_lower_unique ON characters (LOWER(username))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
