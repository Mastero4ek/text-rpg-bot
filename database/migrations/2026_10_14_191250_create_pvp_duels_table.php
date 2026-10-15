<?php

declare(strict_types=1);

use App\Enums\Pvp\PvpDuelStatusEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pvp_duels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('challenge_id')->nullable();
            $table->unsignedBigInteger('a_tg_id');
            $table->unsignedBigInteger('b_tg_id');
            $table->unsignedInteger('round')->default(1);
            $table->string('status')->default(PvpDuelStatusEnum::Active->value);
            $table->unsignedBigInteger('winner_tg_id')->nullable();
            $table->json('a_choice')->nullable();
            $table->json('b_choice')->nullable();
            $table->timestamp('round_deadline_at')->nullable();
            $table->unsignedInteger('round_seq')->default(0);
            $table->unsignedInteger('mutual_skip_streak')->default(0);
            $table->json('log')->nullable();
            $table->timestamps();

            $table->foreign('a_tg_id')->references('tg_id')->on('characters');
            $table->foreign('b_tg_id')->references('tg_id')->on('characters');
            $table->foreign('winner_tg_id')->references('tg_id')->on('characters');
            $table->index(['status', 'a_tg_id']);
            $table->index(['status', 'b_tg_id']);
        });

        Schema::table('fights', function (Blueprint $table) {
            $table->foreign('duel_id')->references('id')->on('pvp_duels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fights', function (Blueprint $table) {
            $table->dropForeign(['duel_id']);
        });

        Schema::dropIfExists('pvp_duels');
    }
};
