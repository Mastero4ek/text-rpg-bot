<?php

declare(strict_types=1);

namespace App\Actions\Pvp;

use App\Actions\Fight\FightClearAction;
use App\Models\Pvp\PvpDuel;
use Illuminate\Support\Facades\DB;

final class PvpDuelClearAction
{
    public function __construct(
        private readonly FightClearAction $clearFight,
    ) {}

    public function handle(PvpDuel $duel): void
    {
        DB::transaction(function () use ($duel): void {
            $locked = PvpDuel::query()->whereKey($duel->id)->lockForUpdate()->first();

            if (! $locked instanceof PvpDuel) {
                return;
            }

            $this->clearFight->handle($locked->a_tg_id);
            $this->clearFight->handle($locked->b_tg_id);

            $locked->delete();
        });
    }
}
