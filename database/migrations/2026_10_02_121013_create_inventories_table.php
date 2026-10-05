<?php

declare(strict_types=1);

use App\Enums\Equipment\TypeEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tg_id');
            $table->string('item_id');
            $table->string('item_name');
            $table->string('item_type')->default(TypeEnum::WEAPON->value);
            $table->string('slot')->nullable();
            $table->unsignedInteger('durability')->nullable();
            $table->unsignedInteger('max_durability')->nullable();
            $table->unsignedTinyInteger('quantity')->default(1);
            $table->json('socketed_gems')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('tg_id');
            $table->foreign('tg_id')->references('tg_id')->on('characters');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
