<?php

declare(strict_types=1);

namespace App\Actions\LuckyBarrel;

use App\Models\Character\Character;
use App\Services\LuckyBarrel\LuckyBarrelService;
use App\Support\LuckyBarrel\LuckyBarrelResult;
use Illuminate\Support\Facades\DB;

final class LuckyBarrelForfeitAction
{
    public function __construct(
        private readonly LuckyBarrelClearAction $clear,
        private readonly LuckyBarrelService $luckyBarrels,
    ) {}

    public function handle(Character $character): LuckyBarrelResult
    {
        return DB::transaction(function () use ($character): LuckyBarrelResult {
            $session = $this->luckyBarrels->findInProgress($character->tg_id);

            if (! $session instanceof \App\Models\LuckyBarrel) {
                return LuckyBarrelResult::forfeit($character);
            }

            $this->clear->handle($character->tg_id);

            $fresh = Character::query()->find($character->tg_id);

            if ($fresh instanceof Character) {
                return LuckyBarrelResult::forfeit($fresh);
            }

            return LuckyBarrelResult::forfeit($character);
        });
    }
}
