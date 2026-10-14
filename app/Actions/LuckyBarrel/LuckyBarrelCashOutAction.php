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

final class LuckyBarrelCashOutAction
{
    public function __construct(
        private readonly LuckyBarrelClearAction $clear,
        private readonly LuckyBarrelService $luckyBarrels,
    ) {}

    public function handle(Character $character): LuckyBarrelResult
    {
        return DB::transaction(function () use ($character): LuckyBarrelResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for lucky barrel cash-out.');
            }

            $session = LuckyBarrel::query()->whereKey($locked->tg_id)->lockForUpdate()->first();

            if (! $session instanceof LuckyBarrel || $session->status !== LuckyBarrelStatusEnum::InProgress) {
                return LuckyBarrelResult::fail(__('telegram.npc.barkeeper.error.no_session'));
            }

            if ($session->current_step < 1) {
                return LuckyBarrelResult::noop($locked, $session);
            }

            $payout = $this->luckyBarrels->payout($session->base_stake, $session->current_step);
            $stake = $session->base_stake;

            Character::query()
                ->where('tg_id', $locked->tg_id)
                ->increment('silver', $payout);

            $this->clear->handle($locked->tg_id);

            $fresh = Character::query()->find($locked->tg_id);

            if (! $fresh instanceof Character) {
                throw new RuntimeException('Character missing after cash-out.');
            }

            return LuckyBarrelResult::win($fresh, $payout, $stake);
        });
    }
}
