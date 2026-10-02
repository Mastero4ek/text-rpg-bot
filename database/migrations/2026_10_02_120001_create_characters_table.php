<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
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
            $table->string('location')->nullable();
            $table->string('onboarding_step')->default(OnboardingStepEnum::NICK->value);
            $table->unsignedInteger('level')->default(0);
            $table->unsignedInteger('exp')->default(0);
            $table->unsignedInteger('gold')->default(20);
            $table->integer('strength')->default(3);
            $table->integer('agility')->default(3);
            $table->integer('instinct')->default(3);
            $table->integer('vitality')->default(3);
            $table->integer('current_hp');
            $table->timestamp('last_hp_update');
            $table->string('weapon_id')->nullable();
            $table->string('armor_id')->nullable();
            $table->integer('stat_points')->default(3);
            $table->unsignedInteger('potions')->default(0);
            $table->unsignedInteger('arena_points')->default(0);
            $table->timestamp('premium_until')->nullable();
            $table->timestamps();
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
