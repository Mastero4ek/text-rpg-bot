<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loadout_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id');
            $table->string('slot');
            $table->unsignedBigInteger('backpack_item_id');
            $table->unique(['tg_id', 'slot']);
            $table->unique('backpack_item_id');
            $table->index('tg_id');
            $table->foreign('tg_id')->references('tg_id')->on('characters')->cascadeOnDelete();
            $table->foreign('backpack_item_id')->references('id')->on('backpack_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loadout_slots');
    }
};
