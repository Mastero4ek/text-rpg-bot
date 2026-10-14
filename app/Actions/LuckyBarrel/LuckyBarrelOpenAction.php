<?php

declare(strict_types=1);

namespace App\Actions\LuckyBarrel;

use App\Enums\LuckyBarrel\LuckyBarrelStatusEnum;
use App\Models\Character\Character;
use App\Models\LuckyBarrel;
use App\Services\LuckyBarrel\LuckyBarrelService;
use App\Support\LuckyBarrel\LuckyBarrelResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LuckyBarrelOpenAction
{
    public function __construct(
        private readonly LuckyBarrelClearAction $clear,
        private readonly LuckyBarrelService $luckyBarrels,
    ) {}

    public function handle(Character $character, int $barrelIndex): LuckyBarrelResult
    {
        return DB::transaction(function () use ($character, $barrelIndex): LuckyBarrelResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for lucky barrel open.');
            }

            $session = LuckyBarrel::query()->whereKey($locked->tg_id)->lockForUpdate()->first();

            if (! $session instanceof LuckyBarrel || $session->status !== LuckyBarrelStatusEnum::InProgress) {
                return LuckyBarrelResult::fail(__('telegram.npc.barkeeper.error.no_session'));
            }

            if ($barrelIndex < 1 || $barrelIndex > LuckyBarrelService::BARREL_COUNT) {
                return LuckyBarrelResult::noop($locked, $session);
            }

            $opened = $session->opened_barrels;

            if (in_array($barrelIndex, $opened, true)) {
                return LuckyBarrelResult::noop($locked, $session);
            }

            if ($barrelIndex === $session->rat_index) {
                $stake = $session->base_stake;
                $ratIndex = $session->rat_index;
                $this->clear->handle($locked->tg_id);

                return LuckyBarrelResult::lose($locked, $stake, $ratIndex);
            }

            $opened[] = $barrelIndex;
            $session->opened_barrels = $opened;
            $session->current_step += 1;
            $session->save();

            if ($session->current_step === LuckyBarrelService::MAX_SAFE_STEPS) {
                $payout = $this->luckyBarrels->payout($session->base_stake, $session->current_step);
                $stake = $session->base_stake;

                Character::query()
                    ->where('tg_id', $locked->tg_id)
                    ->increment('silver', $payout);

                $this->clear->handle($locked->tg_id);

                $fresh = Character::query()->find($locked->tg_id);

                if (! $fresh instanceof Character) {
                    throw new RuntimeException('Character missing after max-step payout.');
                }

                return LuckyBarrelResult::winMax($fresh, $payout, $stake);
            }

            return LuckyBarrelResult::play($locked, $session);
        });
    }
}
