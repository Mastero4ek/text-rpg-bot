<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\Economy\CurrencyEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\BackpackService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;

final class ShopService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly ShopCatalog $catalog,
        private readonly CityQuery $cityQuery,
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

            $shopGate = $this->requireShopCity($character);

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

            if ($this->backpack->owns($tgId, $catalogId)) {
                return ActionResult::fail(__('errors.already_owned'));
            }

            if ($this->backpack->isFull($character)) {
                return ActionResult::fail(__('errors.inventory_full'));
            }

            if (! $this->debitPrice($tgId, $def->currency, $def->price)) {
                return ActionResult::fail($this->notEnoughMessage($def->currency));
            }

            $this->backpack->addItem($tgId, $catalogId);

            return ActionResult::okWithDef(
                $this->characters->findByTgId($tgId),
                $def,
            );
        });
    }

    public function buyPotion(int $tgId): ActionResult
    {
        return $this->buyShopPotion($tgId, $this->bagCatalog->shopPotionId());
    }

    public function buyStaminaPotion(int $tgId): ActionResult
    {
        return $this->buyShopPotion($tgId, $this->bagCatalog->shopStaminaPotionId());
    }

    public function sell(Character $character, int $backpackItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $backpackItemId): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $backpackItemId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->isEquipped()) {
                return ActionResult::fail(__('errors.unequip_first'));
            }

            if (! $this->catalog->hasItem($row->catalog_id)) {
                return ActionResult::fail(__('errors.cannot_sell'));
            }

            $def = $this->catalog->findItem($row->catalog_id);
            $payout = $this->backpack->sellPayout($row);

            $removed = $this->backpack->removeOne($row);

            if (! $removed->ok) {
                return $removed;
            }

            if ($payout > 0) {
                $locked = Character::query()
                    ->where('tg_id', $character->tg_id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    return ActionResult::fail(__('errors.item_not_found'));
                }

                if ($def->currency === CurrencyEnum::GOLD) {
                    $locked->gold += $payout;
                } else {
                    $locked->silver += $payout;
                }

                $locked->save();
            }

            return ActionResult::okWithDef(
                $this->characters->findByTgId($character->tg_id),
                $def,
            );
        });
    }

    private function buyShopPotion(int $tgId, string $catalogId): ActionResult
    {
        return DB::transaction(function () use ($tgId, $catalogId): ActionResult {
            $potion = $this->bagCatalog->findPotion($catalogId);

            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail($this->notEnoughMessage($potion->currency));
            }

            $shopGate = $this->requireShopCity($character);

            if ($shopGate instanceof ActionResult) {
                return $shopGate;
            }

            if (! $this->cityQuery->bagInCityShop($shopGate->id, $catalogId)) {
                return ActionResult::fail(__('errors.potion_unavailable'));
            }

            if (! $this->bag->canAcceptPotion($character, $catalogId)) {
                return ActionResult::fail(__('errors.bag_full'));
            }

            if (! $this->debitPrice($tgId, $potion->currency, $potion->price)) {
                return ActionResult::fail($this->notEnoughMessage($potion->currency));
            }

            $this->bag->addPotion($tgId, $potion->catalogId);

            return ActionResult::okWithPotion(
                $this->characters->findByTgId($tgId),
                $potion,
            );
        });
    }

    private function debitPrice(int $tgId, CurrencyEnum $currency, int $price): bool
    {
        if ($currency === CurrencyEnum::GOLD) {
            $updated = Character::query()
                ->where('tg_id', $tgId)
                ->where('gold', '>=', $price)
                ->decrement('gold', $price);

            return $updated > 0;
        }

        $updated = Character::query()
            ->where('tg_id', $tgId)
            ->where('silver', '>=', $price)
            ->decrement('silver', $price);

        return $updated > 0;
    }

    private function notEnoughMessage(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return __('errors.not_enough_gold');
        }

        return __('errors.not_enough_silver');
    }

    private function requireShopCity(Character $character): ActionResult|City
    {
        if ($character->city_id === null) {
            return ActionResult::fail(__('errors.no_shop'));
        }

        $city = City::query()->find($character->city_id);

        if (! $city instanceof City || ! $city->enabled || ! $city->has_shop) {
            return ActionResult::fail(__('errors.no_shop'));
        }

        return $city;
    }
}
