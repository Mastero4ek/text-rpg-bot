<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('characters_max_rows')->default(100);
            $table->unsignedInteger('portal_cost_silver')->default(0);
            $table->boolean('has_blacksmith')->default(false);
            $table->boolean('has_healer')->default(false);
            $table->boolean('has_buyer')->default(false);
            $table->boolean('has_quest_board')->default(false);
            $table->boolean('has_portal')->default(false);
            $table->boolean('has_forest')->default(false);
            $table->boolean('has_fights_list')->default(false);
            $table->boolean('has_training_room')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
