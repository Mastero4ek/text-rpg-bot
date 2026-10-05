<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\Economy\CurrencyEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Character\CharacterService;
use App\Services\Inventory\InventoryService;
use App\Support\Equipment\EquipmentDef;
use App\Support\Game\ActionResult;
use Illuminate\Support\Facades\DB;

final class ShopService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly InventoryService $inventory,
        private readonly ShopCatalog $catalog,
    ) {}

    public function buyWeapon(int $tgId, string $itemId): ActionResult
    {
        if (! $this->catalog->isShopWeapon($itemId)) {
            return ActionResult::fail(__('errors.pick_train_weapon'));
        }

        return $this->buyCatalogItem($tgId, $itemId);
    }

    public function buyGear(int $tgId, string $itemId): ActionResult
    {
        if ($this->catalog->isShopWeapon($itemId)) {
            return ActionResult::fail(__('errors.item_not_in_shop'));
        }

        if (! $this->catalog->isShopMerchandise($itemId)) {
            return ActionResult::fail(__('errors.item_not_in_shop'));
        }

        return $this->buyCatalogItem($tgId, $itemId);
    }

    public function buyCatalogItem(int $tgId, string $itemId): ActionResult
    {
        return DB::transaction(function () use ($tgId, $itemId): ActionResult {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail(__('common.press_start'));
            }

            if (! $this->catalog->isShopMerchandise($itemId)) {
                return ActionResult::fail(__('errors.item_not_in_shop'));
            }

            $def = $this->catalog->findItem($itemId);

            if ($this->inventory->owns($tgId, $itemId)) {
                return ActionResult::fail(__('errors.already_owned'));
            }

            if ($this->inventory->isFull($character)) {
                return ActionResult::fail(__('errors.inventory_full'));
            }

            if (! $this->debitPrice($tgId, $def)) {
                return ActionResult::fail($this->notEnoughMessage($def->currency));
            }

            $this->inventory->addItem($tgId, $itemId);

            return ActionResult::okWithDef(
                $this->characters->findByTgId($tgId),
                $def,
            );
        });
    }

    public function buyPotion(int $tgId): ActionResult
    {
        return $this->buyShopPotion($tgId, $this->catalog->shopPotionId());
    }

    public function buyStaminaPotion(int $tgId): ActionResult
    {
        return $this->buyShopPotion($tgId, $this->catalog->shopStaminaPotionId());
    }

    public function sell(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->isEquipped()) {
                return ActionResult::fail(__('errors.unequip_first'));
            }

            if (! $this->catalog->hasItem($row->item_id)) {
                return ActionResult::fail(__('errors.cannot_sell'));
            }

            $def = $this->catalog->findItem($row->item_id);
            $payout = $this->inventory->sellPayout($row);

            $this->inventory->removeOne($row);

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

    private function buyShopPotion(int $tgId, string $itemId): ActionResult
    {
        return DB::transaction(function () use ($tgId, $itemId): ActionResult {
            $def = $this->catalog->findItem($itemId);

            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail($this->notEnoughMessage($def->currency));
            }

            if (! $this->inventory->canAcceptItem($character, $itemId)) {
                return ActionResult::fail(__('errors.inventory_full'));
            }

            if (! $this->debitPrice($tgId, $def)) {
                return ActionResult::fail($this->notEnoughMessage($def->currency));
            }

            $this->inventory->addItem($tgId, $def->itemId);

            return ActionResult::okWithDef(
                $this->characters->findByTgId($tgId),
                $def,
            );
        });
    }

    private function debitPrice(int $tgId, EquipmentDef $def): bool
    {
        if ($def->currency === CurrencyEnum::GOLD) {
            $updated = Character::query()
                ->where('tg_id', $tgId)
                ->where('gold', '>=', $def->price)
                ->decrement('gold', $def->price);

            return $updated > 0;
        }

        $updated = Character::query()
            ->where('tg_id', $tgId)
            ->where('silver', '>=', $def->price)
            ->decrement('silver', $def->price);

        return $updated > 0;
    }

    private function notEnoughMessage(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return __('errors.not_enough_gold');
        }

        return __('errors.not_enough_silver');
    }
}
