<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_daily_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id');
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('target_city_id')->nullable()->constrained('cities');
            $table->date('board_date');
            $table->unsignedTinyInteger('slot');
            $table->string('type');
            $table->string('status');
            $table->string('enemy_catalog_id')->nullable();
            $table->string('bag_catalog_id')->nullable();
            $table->string('issuer_npc');
            $table->unsignedSmallInteger('target_kills')->default(1);
            $table->unsignedInteger('reward_silver');
            $table->unsignedInteger('reward_exp');
            $table->json('params');
            $table->timestamps();
            $table->foreign('tg_id')->references('tg_id')->on('characters')->cascadeOnDelete();
            $table->foreign('enemy_catalog_id')->references('catalog_id')->on('enemy_catalog');
            $table->foreign('bag_catalog_id')->references('catalog_id')->on('bag_catalog');
            $table->unique(['tg_id', 'city_id', 'board_date', 'slot']);
            $table->index(['tg_id', 'status', 'board_date']);
            $table->index(['tg_id', 'city_id', 'status']);
            $table->index(['tg_id', 'target_city_id', 'status']);
        });

        Schema::table('fights', function (Blueprint $table) {
            $table->foreign('daily_contract_id')
                ->references('id')
                ->on('character_daily_contracts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fights', function (Blueprint $table) {
            $table->dropForeign(['daily_contract_id']);
        });

        Schema::dropIfExists('character_daily_contracts');
    }
};
