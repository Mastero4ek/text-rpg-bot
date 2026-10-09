<?php

declare(strict_types=1);

namespace App\Services\City;

use App\Enums\Economy\CurrencyEnum;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;

final class HealerService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly CityQuery $cityQuery,
        private readonly CityLedger $ledger,
    ) {}

    public function buyPotion(int $tgId, string $catalogId): ActionResult
    {
        return DB::transaction(function () use ($tgId, $catalogId): ActionResult {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail(__('common.press_start'));
            }

            $city = $this->requireHealerCity($character);

            if ($city instanceof ActionResult) {
                return $city;
            }

            if (! $this->bagCatalog->hasPotion($catalogId)) {
                return ActionResult::fail(__('telegram.npc.healer.error.potion_unavailable'));
            }

            return $this->purchasePotionInCity($tgId, $catalogId, $city);
        });
    }

    private function purchasePotionInCity(int $tgId, string $catalogId, City $city): ActionResult
    {
        $potion = $this->bagCatalog->findPotion($catalogId);

        if (! $this->cityQuery->bagInCityShop($city->id, $catalogId)) {
            return ActionResult::fail(__('telegram.npc.healer.error.potion_unavailable'));
        }

        $character = Character::query()->find($tgId);

        if ($character === null) {
            return ActionResult::fail(__('common.press_start'));
        }

        if (! $this->bag->canAcceptPotion($character, $catalogId)) {
            return ActionResult::fail(__('telegram.npc.healer.error.bag_full'));
        }

        if (! $this->ledger->debit($tgId, $potion->currency, $potion->price)) {
            if ($potion->currency === CurrencyEnum::GOLD) {
                return ActionResult::fail(__('telegram.npc.healer.error.not_enough_gold_potion'));
            }

            return ActionResult::fail(__('telegram.npc.healer.error.not_enough_silver'));
        }

        $this->bag->addPotion($tgId, $potion->catalogId);

        return ActionResult::okWithPotion(
            $this->characters->findByTgId($tgId),
            $potion,
        );
    }

    private function requireHealerCity(Character $character): ActionResult|City
    {
        if ($character->city_id === null) {
            return ActionResult::fail(__('errors.city_unavailable'));
        }

        $city = City::query()->find($character->city_id);

        if (! $city instanceof City || ! $city->enabled || ! $city->has_healer) {
            return ActionResult::fail(__('telegram.npc.healer.error.no_healer'));
        }

        return $city;
    }
}
