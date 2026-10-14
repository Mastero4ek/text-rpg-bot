<?php

declare(strict_types=1);

use App\Enums\LuckyBarrel\LuckyBarrelStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lucky_barrels', function (Blueprint $table) {
            $table->unsignedBigInteger('tg_id')->primary();
            $table->string('status')->default(LuckyBarrelStatusEnum::InProgress->value);
            $table->unsignedInteger('base_stake');
            $table->unsignedTinyInteger('rat_index');
            $table->json('opened_barrels');
            $table->unsignedTinyInteger('current_step')->default(0);
            $table->foreign('tg_id')->references('tg_id')->on('characters');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lucky_barrels');
    }
};
