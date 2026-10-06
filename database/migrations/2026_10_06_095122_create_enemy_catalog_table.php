<?php

declare(strict_types=1);

use App\Enums\Enemy\EnemyKindEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enemy_catalog', function (Blueprint $table) {
            $table->string('catalog_id')->primary();
            $table->string('kind')->default(EnemyKindEnum::FIXED->value);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('level')->nullable();
            $table->unsignedInteger('strength')->nullable();
            $table->unsignedInteger('agility')->nullable();
            $table->unsignedInteger('instinct')->nullable();
            $table->unsignedInteger('vitality')->nullable();
            $table->unsignedInteger('max_hp')->nullable();
            $table->unsignedInteger('weapon_damage')->nullable();
            $table->unsignedInteger('mf_dodge')->nullable();
            $table->unsignedInteger('mf_anti_dodge')->nullable();
            $table->unsignedInteger('mf_crit')->nullable();
            $table->unsignedInteger('mf_anti_crit')->nullable();
            $table->unsignedInteger('power_pct')->nullable();
            $table->unsignedInteger('reward_exp_pct');
            $table->unsignedInteger('reward_silver_min');
            $table->unsignedInteger('reward_silver_max');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index('enabled');
        });

        Schema::create('enemy_drops', function (Blueprint $table) {
            $table->id();
            $table->string('enemy_catalog_id');
            $table->string('bag_catalog_id');
            $table->unsignedInteger('chance_pct');
            $table->timestamps();
            $table->foreign('enemy_catalog_id')
                ->references('catalog_id')
                ->on('enemy_catalog')
                ->cascadeOnDelete();
            $table->foreign('bag_catalog_id')
                ->references('catalog_id')
                ->on('bag_catalog')
                ->restrictOnDelete();
            $table->unique(['enemy_catalog_id', 'bag_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enemy_drops');
        Schema::dropIfExists('enemy_catalog');
    }
};
