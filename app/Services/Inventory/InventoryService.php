<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Character\CharacterService;
use App\Services\Game\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\ActionResult;
use App\Support\Game\EquipmentDef;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InventoryService
{
    public function __construct(
        private readonly ShopCatalog $shop,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function addItem(int $tgId, string $itemId): Inventory
    {
        $def = $this->shop->findItem($itemId);

        $row = new Inventory;
        $row->tg_id = $tgId;
        $row->item_id = $def->itemId;
        $row->item_name = $def->itemName;
        $row->item_type = $def->itemType;
        $row->slot = $def->slot;

        if ($def->maxDurability === null) {
            $row->durability = null;
            $row->max_durability = null;
        } else {
            $row->durability = $def->maxDurability;
            $row->max_durability = $def->maxDurability;
        }

        $row->is_equipped = false;
        $row->save();

        return $row;
    }

    /**
     * @return list<string> names of items that reached 0 this fight
     */
    public function applyFightWearAfterLose(Character $character, int $pierceCount): array
    {
        $extra = $this->extraLossOnLose() + ($pierceCount * $this->extraLossPerPierce());

        return $this->applyFightWearWithExtra($character, $extra);
    }

    /**
     * @return list<string> names of items that reached 0 this fight
     */
    public function applyFightWearAfterWin(Character $character, int $pierceCount): array
    {
        return $this->applyFightWearWithExtra($character, $pierceCount * $this->extraLossPerPierce());
    }

    public function characterMeetsRequirements(Character $character, EquipmentDef $def): bool
    {
        return $def->isAvailableFor(
            $character->level,
            $character->strength,
            $character->agility,
            $character->instinct,
            $character->vitality,
        );
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function damagedList(int $tgId): Collection
    {
        return $this->damagedListForTier($tgId, RepairEnum::NORMAL);
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function damagedVipList(int $tgId): Collection
    {
        return $this->damagedListForTier($tgId, RepairEnum::VIP);
    }

    public function dropUnmetEquipped(Character $character): Character
    {
        $equipped = Inventory::query()
            ->where('tg_id', $character->tg_id)
            ->where('is_equipped', true)
            ->orderBy('id')
            ->get();

        foreach ($equipped as $row) {
            if (! $this->shop->hasItem($row->item_id)) {
                $res = $this->unequip($character, $row->id);

                if ($res->character instanceof Character) {
                    $character = $res->character;
                }

                continue;
            }

            $def = $this->shop->findItem($row->item_id);

            if ($this->characterMeetsRequirements($character, $def)) {
                continue;
            }

            $res = $this->unequip($character, $row->id);

            if ($res->character instanceof Character) {
                $character = $res->character;
            }
        }

        return $character;
    }

    public function equip(Character $character, int $inventoryRowId): ActionResult
    {
        $row = Inventory::query()
            ->where('id', $inventoryRowId)
            ->where('tg_id', $character->tg_id)
            ->first();

        if ($row === null) {
            return ActionResult::fail(__('errors.item_not_found'));
        }

        if (! $this->shop->isEquippable($row->item_id)) {
            return ActionResult::fail(__('errors.cannot_equip'));
        }

        $def = $this->shop->findItem($row->item_id);

        if (! $def->slot instanceof SlotEnum) {
            return ActionResult::fail(__('errors.cannot_equip'));
        }

        return $this->equipToSlot($character, $inventoryRowId, $def->slot);
    }

    public function equipByItemId(Character $character, string $itemId): ActionResult
    {
        if (! $this->owns($character->tg_id, $itemId)) {
            return ActionResult::fail(__('errors.not_in_inventory'));
        }

        $row = $this->findOwned($character->tg_id, $itemId);

        return $this->equip($character, $row->id);
    }

    public function equipToSlot(Character $character, int $inventoryRowId, SlotEnum $slot): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId, $slot): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if (! $this->shop->isEquippable($row->item_id)) {
                return ActionResult::fail(__('errors.cannot_equip'));
            }

            $def = $this->shop->findItem($row->item_id);

            $slotError = $this->equipSlotError($character, $def, $slot);

            if ($slotError !== null) {
                return ActionResult::fail($slotError);
            }

            $reqError = $this->requirementError($character, $def);

            if ($reqError !== null) {
                return ActionResult::fail($reqError);
            }

            $row->slot = $slot;

            Inventory::query()
                ->where('tg_id', $character->tg_id)
                ->where('slot', $slot->value)
                ->where('is_equipped', true)
                ->where('id', '!=', $row->id)
                ->update(['is_equipped' => false]);

            if ($slot === SlotEnum::SHIELD) {
                Inventory::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('slot', SlotEnum::LEFT_HAND->value)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false]);
            }

            if ($slot === SlotEnum::LEFT_HAND) {
                Inventory::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('slot', SlotEnum::SHIELD->value)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false]);
            }

            if (
                $slot === SlotEnum::RIGHT_HAND
                && $def->profile instanceof ProfileEnum
                && $def->profile->isSingleHandWeapon()
            ) {
                Inventory::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('slot', SlotEnum::LEFT_HAND->value)
                    ->where('is_equipped', true)
                    ->update(['is_equipped' => false]);
            }

            $oldCap = $this->characters->maxHp($character);

            $row->is_equipped = true;
            $row->save();

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function fitsEquipSlot(Character $character, EquipmentDef $def, SlotEnum $slot): bool
    {
        return $this->equipSlotError($character, $def, $slot) === null;
    }

    public function findOwned(int $tgId, string $itemId): Inventory
    {
        $row = Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_id', $itemId)
            ->first();

        if ($row === null) {
            throw new RuntimeException("Item {$itemId} not in inventory for {$tgId}");
        }

        return $row;
    }

    /**
     * @param  Collection<int, Inventory>|list<Inventory>  $rows
     */
    public function inventoryText(iterable $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            if ($row->is_equipped) {
                $mark = '✅';
            } else {
                $mark = '·';
            }

            $lines[] = __('profile.inventory_row', [
                'mark' => $mark,
                'id' => $row->id,
                'name' => $row->item_name,
            ]);
        }

        if ($lines === []) {
            return __('profile.inventory_empty');
        }

        return implode("\n", $lines);
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function list(int $tgId): Collection
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->orderBy('id')
            ->get();
    }

    public function owns(int $tgId, string $itemId): bool
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_id', $itemId)
            ->exists();
    }

    public function repair(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->max_durability === null || $row->durability === null) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($row->durability >= $row->max_durability) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            if (! $this->shop->hasItem($row->item_id)) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            $def = $this->shop->findItem($row->item_id);

            if (! $def->repairable) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($def->repairTier === RepairEnum::VIP) {
                return ActionResult::fail(__('errors.needs_vip_smith'));
            }

            $cost = $this->repairCost($row);

            if ($cost <= 0) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $updated = Character::query()
                ->where('tg_id', $character->tg_id)
                ->where('silver', '>=', $cost)
                ->decrement('silver', $cost);

            if ($updated <= 0) {
                return ActionResult::fail(__('errors.not_enough_silver'));
            }

            $oldCap = $this->characters->maxHp($character);

            $row->durability = $row->max_durability;
            $row->save();

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function repairVip(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->max_durability === null || $row->durability === null) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($row->durability >= $row->max_durability) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            if (! $this->shop->hasItem($row->item_id)) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            $def = $this->shop->findItem($row->item_id);

            if (! $def->repairable) {
                return ActionResult::fail(__('errors.cannot_repair'));
            }

            if ($def->repairTier !== RepairEnum::VIP) {
                return ActionResult::fail(__('errors.vip_smith_only_vip'));
            }

            $silverCost = $this->repairVipSilverCost($row);

            if ($silverCost <= 0) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $locked = Character::query()
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            $goldPass = 0;

            if (! $this->characters->hasActivePremium($locked)) {
                $goldPass = $this->vipGoldPass();
            }

            if ($goldPass > 0 && $locked->gold < $goldPass) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            if ($locked->silver < $silverCost) {
                return ActionResult::fail(__('errors.not_enough_silver'));
            }

            $locked->silver -= $silverCost;

            if ($goldPass > 0) {
                $locked->gold -= $goldPass;
            }

            $locked->save();

            $oldCap = $this->characters->maxHp($locked);

            $row->durability = $row->max_durability;
            $row->save();

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function repairVipGoldPass(): int
    {
        return $this->vipGoldPass();
    }

    public function repairVipSilverCost(Inventory $row): int
    {
        return $this->repairCost($row) * $this->vipSilverMultiplier();
    }

    public function repairAll(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $damaged = $this->damagedList($character->tg_id);

            if ($damaged->isEmpty()) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $goldCost = $this->repairAllGoldCost($character);

            if ($goldCost <= 0) {
                return ActionResult::fail(__('errors.already_repaired'));
            }

            $updated = Character::query()
                ->where('tg_id', $character->tg_id)
                ->where('gold', '>=', $goldCost)
                ->decrement('gold', $goldCost);

            if ($updated <= 0) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $oldCap = $this->characters->maxHp($character);

            foreach ($damaged as $row) {
                if ($row->max_durability === null) {
                    continue;
                }

                $row->durability = $row->max_durability;
                $row->save();
            }

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::ok($character);
        });
    }

    public function repairAllGoldCost(Character $character): int
    {
        $silverTotal = 0;

        foreach ($this->damagedList($character->tg_id) as $row) {
            $silverTotal += $this->repairCost($row);
        }

        if ($silverTotal <= 0) {
            return 0;
        }

        $gold = intdiv($silverTotal, $this->goldRepairAllDivisor());

        if ($gold < 1) {
            return 1;
        }

        return $gold;
    }

    public function repairCost(Inventory $row): int
    {
        if ($row->max_durability === null || $row->durability === null) {
            throw new RuntimeException('Item has no durability.');
        }

        $missing = $row->max_durability - $row->durability;

        if ($missing <= 0) {
            return 0;
        }

        $def = $this->shop->findItem($row->item_id);
        $perPoint = $this->repairSilverPerMissingPoint();

        if ($def->reqLevel === null) {
            $reqLevel = 0;
        } else {
            $reqLevel = $def->reqLevel;
        }

        return $missing * $perPoint * ($reqLevel + 1);
    }

    public function unequip(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if (! $row->is_equipped) {
                return ActionResult::fail(__('errors.not_equipped'));
            }

            $row->is_equipped = false;
            $row->save();

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);
            $character->current_hp = $this->characters->clampHp(
                $character->current_hp,
                $newCap,
            );
            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function unequipSlot(Character $character, SlotEnum $slot): ActionResult
    {
        $row = Inventory::query()
            ->where('tg_id', $character->tg_id)
            ->where('slot', $slot->value)
            ->where('is_equipped', true)
            ->first();

        if ($row === null) {
            return ActionResult::fail(__('errors.slot_empty'));
        }

        return $this->unequip($character, $row->id);
    }

    /**
     * @return list<string>
     */
    private function applyFightWearWithExtra(Character $character, int $extraLoss): array
    {
        return DB::transaction(function () use ($character, $extraLoss): array {
            $brokenNames = [];

            $equipped = Inventory::query()
                ->where('tg_id', $character->tg_id)
                ->where('is_equipped', true)
                ->orderBy('id')
                ->get();

            foreach ($equipped as $row) {
                if ($row->max_durability === null || $row->durability === null) {
                    continue;
                }

                if ($row->durability <= 0) {
                    continue;
                }

                if (! $this->shop->hasItem($row->item_id)) {
                    continue;
                }

                $def = $this->shop->findItem($row->item_id);

                if ($def->durabilityLossPerFight === null || $def->durabilityLossPerFight <= 0) {
                    continue;
                }

                $loss = $def->durabilityLossPerFight + $extraLoss;
                $row->durability = max(0, $row->durability - $loss);
                $row->save();

                if ($row->durability === 0) {
                    $brokenNames[] = $row->item_name;
                }
            }

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);
            $character->current_hp = $this->characters->clampHp($character->current_hp, $newCap);
            $character->save();

            return $brokenNames;
        });
    }

    /**
     * @return Collection<int, Inventory>
     */
    private function damagedListForTier(int $tgId, RepairEnum $tier): Collection
    {
        $rows = Inventory::query()
            ->where('tg_id', $tgId)
            ->orderBy('id')
            ->get();

        $damaged = new Collection;

        foreach ($rows as $row) {
            if ($row->max_durability === null || $row->durability === null) {
                continue;
            }

            if ($row->durability >= $row->max_durability) {
                continue;
            }

            if (! $this->shop->hasItem($row->item_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->item_id);

            if (! $def->repairable) {
                continue;
            }

            if ($def->repairTier !== $tier) {
                continue;
            }

            $damaged->push($row);
        }

        return $damaged;
    }

    private function extraLossOnLose(): int
    {
        $wear = $this->wearConfig();

        if (! array_key_exists('extraLossOnLose', $wear) || ! is_int($wear['extraLossOnLose'])) {
            throw new RuntimeException('settings.wear.extraLossOnLose missing.');
        }

        if ($wear['extraLossOnLose'] < 0) {
            throw new RuntimeException('settings.wear.extraLossOnLose must be >= 0.');
        }

        return $wear['extraLossOnLose'];
    }

    private function extraLossPerPierce(): int
    {
        $wear = $this->wearConfig();

        if (! array_key_exists('extraLossPerPierce', $wear) || ! is_int($wear['extraLossPerPierce'])) {
            throw new RuntimeException('settings.wear.extraLossPerPierce missing.');
        }

        if ($wear['extraLossPerPierce'] < 0) {
            throw new RuntimeException('settings.wear.extraLossPerPierce must be >= 0.');
        }

        return $wear['extraLossPerPierce'];
    }

    /**
     * @return array<string, mixed>
     */
    private function wearConfig(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('wear', $settings) || ! is_array($settings['wear'])) {
            throw new RuntimeException('settings.wear missing.');
        }

        return $settings['wear'];
    }

    private function goldRepairAllDivisor(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('repair', $settings) || ! is_array($settings['repair'])) {
            throw new RuntimeException('settings.repair missing.');
        }

        $repair = $settings['repair'];

        if (! array_key_exists('goldRepairAllDivisor', $repair) || ! is_int($repair['goldRepairAllDivisor'])) {
            throw new RuntimeException('settings.repair.goldRepairAllDivisor missing.');
        }

        if ($repair['goldRepairAllDivisor'] < 1) {
            throw new RuntimeException('settings.repair.goldRepairAllDivisor must be >= 1.');
        }

        return $repair['goldRepairAllDivisor'];
    }

    private function vipGoldPass(): int
    {
        $vip = $this->vipRepairConfig();

        if (! array_key_exists('goldPass', $vip) || ! is_int($vip['goldPass'])) {
            throw new RuntimeException('settings.vipRepair.goldPass missing.');
        }

        if ($vip['goldPass'] < 0) {
            throw new RuntimeException('settings.vipRepair.goldPass must be >= 0.');
        }

        return $vip['goldPass'];
    }

    /**
     * @return array<string, mixed>
     */
    private function vipRepairConfig(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('vipRepair', $settings) || ! is_array($settings['vipRepair'])) {
            throw new RuntimeException('settings.vipRepair missing.');
        }

        return $settings['vipRepair'];
    }

    private function vipSilverMultiplier(): int
    {
        $vip = $this->vipRepairConfig();

        if (! array_key_exists('silverMultiplier', $vip) || ! is_int($vip['silverMultiplier'])) {
            throw new RuntimeException('settings.vipRepair.silverMultiplier missing.');
        }

        if ($vip['silverMultiplier'] < 1) {
            throw new RuntimeException('settings.vipRepair.silverMultiplier must be >= 1.');
        }

        return $vip['silverMultiplier'];
    }

    private function repairSilverPerMissingPoint(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('repair', $settings) || ! is_array($settings['repair'])) {
            throw new RuntimeException('settings.repair missing.');
        }

        $repair = $settings['repair'];

        if (! array_key_exists('silverPerMissingPoint', $repair) || ! is_int($repair['silverPerMissingPoint'])) {
            throw new RuntimeException('settings.repair.silverPerMissingPoint missing.');
        }

        if ($repair['silverPerMissingPoint'] < 1) {
            throw new RuntimeException('settings.repair.silverPerMissingPoint must be >= 1.');
        }

        return $repair['silverPerMissingPoint'];
    }

    private function equipSlotError(Character $character, EquipmentDef $def, SlotEnum $slot): ?string
    {
        if ($def->itemType === TypeEnum::WEAPON) {
            if (! $def->profile instanceof ProfileEnum) {
                return __('errors.cannot_equip');
            }

            if ($def->profile->allowsDualWield()) {
                if ($slot !== SlotEnum::RIGHT_HAND && $slot !== SlotEnum::LEFT_HAND) {
                    return __('errors.cannot_equip_in_slot');
                }

                if ($slot === SlotEnum::LEFT_HAND) {
                    $rightProfile = $this->equippedRightHandProfile($character);

                    if ($rightProfile instanceof ProfileEnum && $rightProfile->isSingleHandWeapon()) {
                        return __('errors.cannot_dual_with_single_hand');
                    }
                }

                return null;
            }

            if ($def->profile->isSingleHandWeapon()) {
                if ($slot !== SlotEnum::RIGHT_HAND) {
                    return __('errors.cannot_equip_in_slot');
                }

                return null;
            }

            return __('errors.cannot_equip');
        }

        if (! $def->slot instanceof SlotEnum) {
            return __('errors.cannot_equip');
        }

        if ($def->slot !== $slot) {
            return __('errors.cannot_equip_in_slot');
        }

        return null;
    }

    private function equippedRightHandProfile(Character $character): ?ProfileEnum
    {
        $row = Inventory::query()
            ->where('tg_id', $character->tg_id)
            ->where('slot', SlotEnum::RIGHT_HAND->value)
            ->where('is_equipped', true)
            ->first();

        if ($row === null) {
            return null;
        }

        if (! $this->shop->hasItem($row->item_id)) {
            return null;
        }

        $def = $this->shop->findItem($row->item_id);

        if (! $def->profile instanceof ProfileEnum) {
            return null;
        }

        return $def->profile;
    }

    private function requirementError(Character $character, EquipmentDef $def): ?string
    {
        $unmet = $def->unmetRequirement(
            $character->level,
            $character->strength,
            $character->agility,
            $character->instinct,
            $character->vitality,
        );

        if ($unmet === null) {
            return null;
        }

        if ($unmet === 'level') {
            return __('errors.req_level', ['value' => $def->reqLevel]);
        }

        if ($unmet === 'strength') {
            return __('errors.req_strength', ['value' => $def->reqStrength]);
        }

        if ($unmet === 'agility') {
            return __('errors.req_agility', ['value' => $def->reqAgility]);
        }

        if ($unmet === 'instinct') {
            return __('errors.req_instinct', ['value' => $def->reqInstinct]);
        }

        return __('errors.req_vitality', ['value' => $def->reqVitality]);
    }
}
