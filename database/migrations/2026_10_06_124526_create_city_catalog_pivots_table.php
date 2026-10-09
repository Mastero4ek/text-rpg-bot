<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_backpack_catalog', function (Blueprint $table) {
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('backpack_catalog_id');
            $table->foreign('backpack_catalog_id')->references('catalog_id')->on('backpack_catalog')->cascadeOnDelete();
            $table->primary(['city_id', 'backpack_catalog_id']);
        });

        Schema::create('city_bag_catalog', function (Blueprint $table) {
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('bag_catalog_id');
            $table->foreign('bag_catalog_id')->references('catalog_id')->on('bag_catalog')->cascadeOnDelete();
            $table->primary(['city_id', 'bag_catalog_id']);
        });

        Schema::create('city_enemy_catalog', function (Blueprint $table) {
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('enemy_catalog_id');
            $table->foreign('enemy_catalog_id')->references('catalog_id')->on('enemy_catalog')->cascadeOnDelete();
            $table->primary(['city_id', 'enemy_catalog_id']);
        });

        Schema::create('city_training_enemy_catalog', function (Blueprint $table) {
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('enemy_catalog_id');
            $table->foreign('enemy_catalog_id')->references('catalog_id')->on('enemy_catalog')->cascadeOnDelete();
            $table->primary(['city_id', 'enemy_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_training_enemy_catalog');
        Schema::dropIfExists('city_enemy_catalog');
        Schema::dropIfExists('city_bag_catalog');
        Schema::dropIfExists('city_backpack_catalog');
    }
};
