<?php

declare(strict_types=1);

namespace App\Actions\LuckyBarrel;

use App\Enums\LuckyBarrel\LuckyBarrelStatusEnum;
use App\Models\Character\Character;
use App\Models\LuckyBarrel;
use App\Services\LuckyBarrel\LuckyBarrelService;
use App\Support\LuckyBarrel\LuckyBarrelResult;
use App\Support\Random\RandomSourceContract;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LuckyBarrelStartAction
{
    public function __construct(
        private readonly LuckyBarrelService $luckyBarrels,
        private readonly RandomSourceContract $random,
    ) {}

    public function handle(Character $character, int $stake): LuckyBarrelResult
    {
        return DB::transaction(function () use ($character, $stake): LuckyBarrelResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for lucky barrel start.');
            }

            $existing = $this->luckyBarrels->findInProgress($locked->tg_id);

            if ($existing instanceof LuckyBarrel) {
                return LuckyBarrelResult::play($locked, $existing);
            }

            if (! $this->luckyBarrels->isAllowedStake($stake)) {
                return LuckyBarrelResult::fail(__('telegram.npc.barkeeper.error.bad_stake'));
            }

            if ($locked->silver < $stake) {
                return LuckyBarrelResult::fail(__('telegram.npc.barkeeper.error.not_enough_silver'));
            }

            $paid = Character::query()
                ->where('tg_id', $locked->tg_id)
                ->where('silver', '>=', $stake)
                ->decrement('silver', $stake);

            if ($paid <= 0) {
                return LuckyBarrelResult::fail(__('telegram.npc.barkeeper.error.not_enough_silver'));
            }

            $fresh = Character::query()->find($locked->tg_id);

            if (! $fresh instanceof Character) {
                throw new RuntimeException('Character missing after stake debit.');
            }

            $session = new LuckyBarrel;
            $session->tg_id = $fresh->tg_id;
            $session->status = LuckyBarrelStatusEnum::InProgress;
            $session->base_stake = $stake;
            $session->rat_index = $this->luckyBarrels->ratIndexFromFloat($this->random->float());
            $session->opened_barrels = [];
            $session->current_step = 0;
            $session->save();

            return LuckyBarrelResult::play($fresh, $session);
        });
    }
}
