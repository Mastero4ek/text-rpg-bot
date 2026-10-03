<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\Equipment\CurrencyEnum;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Inventory\InventoryService;
use App\Support\Game\ActionResult;
use App\Support\Game\EquipmentDef;
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

            if (! $this->catalog->isShopWeapon($itemId)) {
                return ActionResult::fail(__('errors.pick_train_weapon'));
            }

            $def = $this->catalog->findItem($itemId);

            if ($this->inventory->owns($tgId, $itemId)) {
                return ActionResult::fail(__('errors.already_owned'));
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
        return DB::transaction(function () use ($tgId): ActionResult {
            $def = $this->catalog->findItem($this->catalog->shopPotionId());

            $character = Character::query()->find($tgId);

            if ($character === null) {
                return ActionResult::fail($this->notEnoughMessage($def->currency));
            }

            if (! $this->debitPrice($tgId, $def)) {
                return ActionResult::fail($this->notEnoughMessage($def->currency));
            }

            $character = $this->characters->findByTgId($tgId);
            $character->potions += 1;
            $character->save();

            return ActionResult::ok($character);
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
