<?php

declare(strict_types=1);

namespace App\Actions\DailyContract;

use App\Enums\City\PveZoneEnum;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Services\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DailyContractStartHuntAction
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly DailyContractExpireStaleAction $expireStale,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
    ) {}

    public function handle(Character $character, City $city, int $contractId): ActionResult
    {
        return DB::transaction(function () use ($character, $city, $contractId): ActionResult {
            $this->expireStale->handle($character->tg_id);

            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for DailyContractStartHunt.');
            }

            if ($locked->current_hp <= 0) {
                return ActionResult::fail(__('telegram.location.error.no_hp'));
            }

            $contract = CharacterDailyContract::query()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if (! $contract instanceof CharacterDailyContract) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            if ($contract->tg_id !== $locked->tg_id) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            if ($contract->city_id !== $city->id) {
                return ActionResult::fail(__('telegram.daily.error.wrong_city'));
            }

            if ($contract->status !== DailyContractStatusEnum::Accepted) {
                return ActionResult::fail(__('telegram.daily.error.not_accepted'));
            }

            if ($contract->type !== DailyContractTypeEnum::Hunt) {
                return ActionResult::fail(__('telegram.daily.error.not_accepted'));
            }

            $zone = $this->contractZone($contract);

            if (! $zone->cityHas($city)) {
                return ActionResult::fail(__($zone->emptyErrorKey()));
            }

            if (! is_string($contract->enemy_catalog_id) || $contract->enemy_catalog_id === '') {
                throw new RuntimeException('Daily hunt enemy_catalog_id missing.');
            }

            $catalog = EnemyCatalog::query()->find($contract->enemy_catalog_id);

            if (! $catalog instanceof EnemyCatalog) {
                throw new RuntimeException('Daily hunt enemy catalog missing: ' . $contract->enemy_catalog_id);
            }

            $locked = $this->characters->applyRegen($locked);
            $enemy = $this->enemies->makeFromCatalog($catalog, $locked);
            $this->fights->createDailyQuest($locked, $enemy, $contract->id, $zone->fightReturn());

            return ActionResult::ok($locked);
        });
    }

    private function contractZone(CharacterDailyContract $contract): PveZoneEnum
    {
        $zoneKey = 'forest';

        if (
            array_key_exists('zone', $contract->params)
            && is_string($contract->params['zone'])
            && $contract->params['zone'] !== ''
        ) {
            $zoneKey = $contract->params['zone'];
        }

        $zone = PveZoneEnum::tryFrom($zoneKey);

        if (! $zone instanceof PveZoneEnum) {
            throw new RuntimeException('Daily hunt zone invalid: ' . $zoneKey);
        }

        return $zone;
    }
}
