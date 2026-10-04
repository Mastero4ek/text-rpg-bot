<?php

declare(strict_types=1);

use App\Enums\FightKindEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fights', function (Blueprint $table) {
            $table->unsignedBigInteger('tg_id')->primary();
            $table->string('kind')->default(FightKindEnum::PVE->value);
            $table->boolean('tutorial');
            $table->integer('player_hp');
            $table->integer('player_max_hp');
            $table->json('enemy');
            $table->string('step');
            $table->string('player_stance')->nullable();
            $table->string('player_attack')->nullable();
            $table->string('player_attack_second')->nullable();
            $table->string('player_defend')->nullable();
            $table->string('player_defend_second')->nullable();
            $table->boolean('use_potion')->default(false);
            $table->unsignedInteger('pierce_count')->default(0);
            $table->json('log');
            $table->foreign('tg_id')->references('tg_id')->on('characters');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fights');
    }
};
