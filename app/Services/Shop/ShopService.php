<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\ItemTypeEnum;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Inventory\InventoryService;
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
        return DB::transaction(function () use ($tgId, $itemId): ActionResult {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail(__('common.press_start'));
            }

            if (! $this->catalog->hasItem($itemId)) {
                return ActionResult::fail(__('errors.pick_train_weapon'));
            }

            $def = $this->catalog->findItem($itemId);

            if ($def->itemType !== ItemTypeEnum::WEAPON) {
                return ActionResult::fail(__('errors.pick_train_weapon'));
            }

            if ($this->inventory->owns($tgId, $itemId)) {
                return ActionResult::fail(__('errors.already_owned'));
            }

            $updated = Character::query()
                ->where('tg_id', $tgId)
                ->where('gold', '>=', $def->price)
                ->decrement('gold', $def->price);

            if ($updated === 0) {
                return ActionResult::fail(__('errors.not_enough_gold'));
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
        return DB::transaction(function () use ($tgId): ActionResult {
            $price = $this->catalog->potionPrice();

            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            if ($character->gold < $price) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $character->gold -= $price;
            $character->potions += 1;
            $character->save();

            return ActionResult::ok($character);
        });
    }
}
