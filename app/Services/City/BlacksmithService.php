<?php

declare(strict_types=1);

namespace App\Services\City;

use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\BackpackService;
use App\Services\CharacterService;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;

final class BlacksmithService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly ShopCatalog $catalog,
        private readonly CityQuery $cityQuery,
        private readonly CityLedger $ledger,
    ) {}

    public function buyWeapon(int $tgId, string $catalogId): ActionResult
    {
        if (! $this->catalog->isShopWeapon($catalogId)) {
            return ActionResult::fail(__('errors.pick_train_weapon'));
        }

        return $this->buyCatalogItem($tgId, $catalogId);
    }

    public function buyGear(int $tgId, string $catalogId): ActionResult
    {
        if ($this->catalog->isShopWeapon($catalogId)) {
            return ActionResult::fail(__('errors.item_not_in_shop'));
        }

        if (! $this->catalog->isShopMerchandise($catalogId)) {
            return ActionResult::fail(__('errors.item_not_in_shop'));
        }

        return $this->buyCatalogItem($tgId, $catalogId);
    }

    public function buyCatalogItem(int $tgId, string $catalogId): ActionResult
    {
        return DB::transaction(function () use ($tgId, $catalogId): ActionResult {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail(__('common.press_start'));
            }

            $shopGate = $this->requireBlacksmithCity($character);

            if ($shopGate instanceof ActionResult) {
                return $shopGate;
            }

            if (! $this->cityQuery->backpackInCityShop($shopGate->id, $catalogId)) {
                return ActionResult::fail(__('errors.item_not_in_shop'));
            }

            if (! $this->catalog->isShopMerchandise($catalogId)) {
                return ActionResult::fail(__('errors.item_not_in_shop'));
            }

            $def = $this->catalog->findItem($catalogId);

            if ($this->backpack->isFull($character)) {
                return ActionResult::fail(__('errors.inventory_full'));
            }

            if (! $this->ledger->debit($tgId, $def->currency, $def->price)) {
                return ActionResult::fail($this->ledger->notEnoughMessage($def->currency));
            }

            $this->backpack->addItem($tgId, $catalogId);

            return ActionResult::okWithDef(
                $this->characters->findByTgId($tgId),
                $def,
            );
        });
    }

    private function requireBlacksmithCity(Character $character): ActionResult|City
    {
        if ($character->city_id === null) {
            return ActionResult::fail(__('errors.no_blacksmith'));
        }

        $city = City::query()->find($character->city_id);

        if (! $city instanceof City || ! $city->enabled || ! $city->has_blacksmith) {
            return ActionResult::fail(__('errors.no_blacksmith'));
        }

        return $city;
    }
}
