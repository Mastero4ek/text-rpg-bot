<?php

declare(strict_types=1);

use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\RepairTierEnum;
use App\Enums\Equipment\TypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->string('item_id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('item_type')->default(TypeEnum::WEAPON->value);
            $table->string('slot')->nullable();
            $table->string('profile')->nullable();
            $table->unsignedTinyInteger('tier')->nullable();
            $table->boolean('in_shop')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('price')->default(0);
            $table->string('currency')->default(CurrencyEnum::SILVER->value);
            $table->boolean('vip_only')->default(false);
            $table->string('repair_tier')->default(RepairTierEnum::NORMAL->value);
            $table->unsignedInteger('weapon_damage')->default(0);
            $table->integer('stat_bonus')->default(0);
            $table->unsignedInteger('armor')->default(0);
            $table->unsignedInteger('mf_dodge')->default(0);
            $table->unsignedInteger('mf_anti_dodge')->default(0);
            $table->unsignedInteger('mf_crit')->default(0);
            $table->unsignedInteger('mf_anti_crit')->default(0);
            $table->string('effect_type')->nullable();
            $table->unsignedInteger('effect_value')->nullable();
            $table->unsignedInteger('req_strength')->nullable();
            $table->unsignedInteger('req_agility')->nullable();
            $table->unsignedInteger('req_instinct')->nullable();
            $table->unsignedInteger('req_level')->nullable();
            $table->unsignedInteger('max_durability')->nullable();
            $table->unsignedInteger('durability_loss_per_fight')->nullable();
            $table->boolean('repairable')->default(true);
            $table->unsignedTinyInteger('gem_slots')->nullable();
            $table->json('allowed_gem_types')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_type', 'enabled', 'in_shop']);
            $table->index(['slot', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
