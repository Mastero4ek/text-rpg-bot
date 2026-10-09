<?php

declare(strict_types=1);

namespace App\Services\City;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\BackpackService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;

final class BuyerService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly ShopCatalog $catalog,
        private readonly CityQuery $cityQuery,
        private readonly CityLedger $ledger,
    ) {}

    public function buyPotion(int $tgId): ActionResult
    {
        return $this->buyShopPotion($tgId, $this->bagCatalog->shopPotionId());
    }

    public function buyStaminaPotion(int $tgId): ActionResult
    {
        return $this->buyShopPotion($tgId, $this->bagCatalog->shopStaminaPotionId());
    }

    public function sellBackpackItem(Character $character, int $backpackItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $backpackItemId): ActionResult {
            $gate = $this->requireBuyerCity($character);

            if ($gate instanceof ActionResult) {
                return $gate;
            }

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

            $credited = $this->creditPayout($character->tg_id, $def->currency, $payout);

            if (! $credited->ok) {
                return $credited;
            }

            return ActionResult::okWithDef(
                $this->characters->findByTgId($character->tg_id),
                $def,
            );
        });
    }

    public function sellBagItem(Character $character, int $bagItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $bagItemId): ActionResult {
            $gate = $this->requireBuyerCity($character);

            if ($gate instanceof ActionResult) {
                return $gate;
            }

            $row = BagItem::query()
                ->where('id', $bagItemId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->backpack_item_id !== null) {
                return ActionResult::fail(__('errors.gem_socketed'));
            }

            if ($row->kind === BagKindEnum::POTION) {
                if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                    return ActionResult::fail(__('errors.cannot_sell'));
                }
            } elseif (! $this->bagCatalog->hasGem($row->catalog_id)) {
                return ActionResult::fail(__('errors.cannot_sell'));
            }

            $currency = $this->bag->sellCurrency($row);
            $payout = $this->bag->sellPayout($row);

            $removed = $this->bag->sellRemoveOne($row);

            if (! $removed->ok) {
                return $removed;
            }

            $credited = $this->creditPayout($character->tg_id, $currency, $payout);

            if (! $credited->ok) {
                return $credited;
            }

            return ActionResult::ok($this->characters->findByTgId($character->tg_id));
        });
    }

    private function buyShopPotion(int $tgId, string $catalogId): ActionResult
    {
        return DB::transaction(function () use ($tgId, $catalogId): ActionResult {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail(__('common.press_start'));
            }

            $shopGate = $this->requireBuyerCity($character);

            if ($shopGate instanceof ActionResult) {
                return $shopGate;
            }

            return $this->purchasePotionInCity($tgId, $catalogId, $shopGate);
        });
    }

    private function creditPayout(int $tgId, CurrencyEnum $currency, int $payout): ActionResult
    {
        if ($payout <= 0) {
            return ActionResult::ok($this->characters->findByTgId($tgId));
        }

        $locked = Character::query()
            ->where('tg_id', $tgId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            return ActionResult::fail(__('errors.item_not_found'));
        }

        if ($currency === CurrencyEnum::GOLD) {
            $locked->gold += $payout;
        } else {
            $locked->silver += $payout;
        }

        $locked->save();

        return ActionResult::ok($locked);
    }

    private function purchasePotionInCity(int $tgId, string $catalogId, City $city): ActionResult
    {
        $potion = $this->bagCatalog->findPotion($catalogId);

        if (! $this->cityQuery->bagInCityShop($city->id, $catalogId)) {
            return ActionResult::fail(__('errors.potion_unavailable'));
        }

        $character = Character::query()->find($tgId);

        if ($character === null) {
            return ActionResult::fail(__('common.press_start'));
        }

        if (! $this->bag->canAcceptPotion($character, $catalogId)) {
            return ActionResult::fail(__('errors.bag_full'));
        }

        if (! $this->ledger->debit($tgId, $potion->currency, $potion->price)) {
            return ActionResult::fail($this->ledger->notEnoughMessage($potion->currency));
        }

        $this->bag->addPotion($tgId, $potion->catalogId);

        return ActionResult::okWithPotion(
            $this->characters->findByTgId($tgId),
            $potion,
        );
    }

    private function requireBuyerCity(Character $character): ActionResult|City
    {
        if ($character->city_id === null) {
            return ActionResult::fail(__('errors.no_buyer'));
        }

        $city = City::query()->find($character->city_id);

        if (! $city instanceof City || ! $city->enabled || ! $city->has_buyer) {
            return ActionResult::fail(__('errors.no_buyer'));
        }

        return $city;
    }
}
