<?php

declare(strict_types=1);

use App\Enums\Bag\BagKindEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bag_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id');
            $table->string('kind')->default(BagKindEnum::GEM->value);
            $table->string('catalog_id');
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->unsignedInteger('durability')->nullable();
            $table->unsignedBigInteger('backpack_item_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('tg_id');
            $table->index('backpack_item_id');
            $table->foreign('tg_id')->references('tg_id')->on('characters');
            $table->foreign('backpack_item_id')->references('id')->on('backpack_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bag_items');
    }
};
