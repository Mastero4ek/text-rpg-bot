<?php

declare(strict_types=1);

use App\Enums\Pvp\PvpChallengeStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pvp_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('challenger_tg_id');
            $table->foreignId('city_id')->constrained('cities');
            $table->unsignedInteger('stake_silver')->default(0);
            $table->unsignedInteger('stake_gold')->default(0);
            $table->string('status')->default(PvpChallengeStatusEnum::Open->value);
            $table->timestamp('expires_at');
            $table->unsignedBigInteger('accepted_by_tg_id')->nullable();
            $table->unsignedBigInteger('duel_id')->nullable();
            $table->timestamps();

            $table->foreign('challenger_tg_id')->references('tg_id')->on('characters');
            $table->foreign('accepted_by_tg_id')->references('tg_id')->on('characters');
            $table->foreign('duel_id')->references('id')->on('pvp_duels')->nullOnDelete();
            $table->index(['city_id', 'status']);
            $table->index(['challenger_tg_id', 'status']);
        });

        Schema::table('pvp_duels', function (Blueprint $table) {
            $table->foreign('challenge_id')->references('id')->on('pvp_challenges')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pvp_duels', function (Blueprint $table) {
            $table->dropForeign(['challenge_id']);
        });

        Schema::dropIfExists('pvp_challenges');
    }
};
