<?php

declare(strict_types=1);

namespace App\Services\Backpack;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use App\Services\Bag\BagCatalog as BagCatalogService;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\ActionResult;
use App\Support\Equipment\EquipmentDef;
use App\Support\Equipment\EquippedLoadout;
use App\Support\Gem\GemMfText;
use App\Support\Mf;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LoadoutService
{
    public function __construct(
        private readonly ShopCatalog $shop,
        private readonly BagService $bag,
        private readonly GameConfig $config,
    ) {}

    /**
     * @return list<array{
     *     kind: 'note'|'plain'|'delta',
     *     text?: string,
     *     label?: string,
     *     before?: string,
     *     after?: string,
     *     delta?: int
     * }>
     */
    public function equipStatChanges(Character $character, BackpackItem $row, SlotEnum $slot): array
    {
        $before = $this->forCharacter($character);
        $afterRows = $before->rowsBySlot;

        foreach ($afterRows as $key => $wornRow) {
            if ($wornRow instanceof BackpackItem && $wornRow->id === $row->id) {
                $afterRows[$key] = null;
            }
        }

        if ($slot === SlotEnum::SHIELD) {
            $afterRows[SlotEnum::LEFT_HAND->value] = null;
        }

        if ($slot === SlotEnum::LEFT_HAND) {
            $afterRows[SlotEnum::SHIELD->value] = null;
        }

        if ($slot === SlotEnum::RIGHT_HAND) {
            $def = $this->shop->findItem($row->catalog_id);

            if ($def->profile instanceof ProfileEnum && $def->profile->isSingleHandWeapon()) {
                $afterRows[SlotEnum::LEFT_HAND->value] = null;
            }
        }

        $replaced = $afterRows[$slot->value] ?? null;
        $afterRows[$slot->value] = $row;
        $after = $this->forRows($character, $afterRows);

        /** @var list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}> $lines */
        $lines = [];

        if ($replaced instanceof BackpackItem && $replaced->id !== $row->id) {
            $lines[] = [
                'kind' => 'note',
                'text' => $this->equipLang('admin.actions.equip.replaces', ['name' => $replaced->item_name]),
            ];
        }

        $this->appendLoadoutStatChangeLines($lines, $character, $before, $after);

        if ($lines === []) {
            return [[
                'kind' => 'note',
                'text' => $this->equipLang('admin.actions.equip.no_stat_changes'),
            ]];
        }

        return $lines;
    }

    /**
     * @return list<array{
     *     kind: 'note'|'plain'|'delta',
     *     text?: string,
     *     label?: string,
     *     before?: string,
     *     after?: string,
     *     delta?: int
     * }>
     */
    public function unequipStatChanges(Character $character, BackpackItem $row): array
    {
        $before = $this->forCharacter($character);
        $afterRows = $before->rowsBySlot;
        $found = false;

        foreach ($afterRows as $key => $wornRow) {
            if ($wornRow instanceof BackpackItem && $wornRow->id === $row->id) {
                $afterRows[$key] = null;
                $found = true;
            }
        }

        if (! $found) {
            return [[
                'kind' => 'note',
                'text' => $this->equipLang('admin.actions.unequip.no_stat_changes'),
            ]];
        }

        $after = $this->forRows($character, $afterRows);

        /** @var list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}> $lines */
        $lines = [];
        $this->appendLoadoutStatChangeLines($lines, $character, $before, $after);

        if ($lines === []) {
            return [[
                'kind' => 'note',
                'text' => $this->equipLang('admin.actions.unequip.no_stat_changes'),
            ]];
        }

        return $lines;
    }

    /**
     * @return list<array{
     *     kind: 'note'|'plain'|'delta',
     *     text?: string,
     *     label?: string,
     *     before?: string,
     *     after?: string,
     *     delta?: int
     * }>
     */
    public function socketStatChanges(Character $character, BackpackItem $host, string $gemCatalogId): array
    {
        $catalog = app(BagCatalogService::class);

        if (! $catalog->hasGem($gemCatalogId)) {
            return [[
                'kind' => 'note',
                'text' => $this->equipLang('errors.gem_not_found'),
            ]];
        }

        $def = $catalog->findGem($gemCatalogId);

        if (! $host->isEquipped()) {
            return [
                [
                    'kind' => 'note',
                    'text' => $this->equipLang('admin.actions.socket_gem.bonus', [
                        'bonus' => GemMfText::forDef($def),
                    ]),
                ],
                [
                    'kind' => 'note',
                    'text' => $this->equipLang('admin.actions.socket_gem.not_equipped'),
                ],
            ];
        }

        $before = $this->forCharacter($character);
        $afterMf = $before->mf->merge($def->mf);

        /** @var list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}> $lines */
        $lines = [];
        $this->pushStatChangeLine($lines, 'admin.labels.mf_dodge', $before->mf->dodge, $afterMf->dodge);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_anti_dodge', $before->mf->antiDodge, $afterMf->antiDodge);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_crit', $before->mf->crit, $afterMf->crit);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_anti_crit', $before->mf->antiCrit, $afterMf->antiCrit);

        if ($lines === []) {
            return [[
                'kind' => 'note',
                'text' => $this->equipLang('admin.actions.socket_gem.no_stat_changes'),
            ]];
        }

        return $lines;
    }

    public function forCharacter(Character $character): EquippedLoadout
    {
        $rowsBySlot = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $rowsBySlot[$slot->value] = null;
        }

        $slots = LoadoutSlot::query()
            ->where('tg_id', $character->tg_id)
            ->with('backpackItem')
            ->orderBy('id')
            ->get();

        foreach ($slots as $loadoutSlot) {
            $worn = $loadoutSlot->slot;

            if (! $worn->isGameplayEquipSlot()) {
                continue;
            }

            $row = $loadoutSlot->backpackItem;

            if (! $row instanceof BackpackItem) {
                continue;
            }

            $rowsBySlot[$worn->value] = $row;
        }

        return $this->forRows($character, $rowsBySlot);
    }

    public function forRows(Character $character, array $rowsBySlot): EquippedLoadout
    {
        $normalized = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $normalized[$slot->value] = null;
        }

        $mainHandDamageMin = 0;
        $mainHandDamageMax = 0;
        $offHandDamageMin = 0;
        $offHandDamageMax = 0;
        $statBonus = 0;
        $bodyMf = new Mf(0, 0, 0, 0);
        $mainHandMf = new Mf(0, 0, 0, 0);
        $offHandMf = new Mf(0, 0, 0, 0);
        $armorByZone = [
            ZoneEnum::HEAD->value => 0,
            ZoneEnum::CHEST->value => 0,
            ZoneEnum::BELLY->value => 0,
            ZoneEnum::LEGS->value => 0,
        ];
        $mainHandDual = false;
        $offHandDual = false;

        foreach (SlotEnum::gameplayEquipSlots() as $worn) {
            if (! array_key_exists($worn->value, $rowsBySlot)) {
                continue;
            }

            $row = $rowsBySlot[$worn->value];

            if (! $row instanceof BackpackItem) {
                continue;
            }

            if (! $this->shop->hasItem($row->catalog_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->catalog_id);

            if (! $this->defFitsWornSlot($def, $worn)) {
                continue;
            }

            if (! $def->isAvailableFor(
                $character->level,
                $character->strength,
                $character->agility,
                $character->instinct,
                $character->vitality,
            )) {
                continue;
            }

            $normalized[$worn->value] = $row;

            if (! $this->rowGivesBonuses($row)) {
                continue;
            }

            $rowMf = $def->mf->merge($this->bag->mfFromSocketed($row));
            $statBonus += $def->statBonus;

            if ($worn === SlotEnum::RIGHT_HAND) {
                $mainHandMf = $mainHandMf->merge($rowMf);
                $mainHandDamageMin += $def->weaponDamageMin;
                $mainHandDamageMax += $def->weaponDamageMax;

                if ($def->profile instanceof ProfileEnum && $def->profile->allowsDualWield()) {
                    $mainHandDual = true;
                }
            } elseif ($worn === SlotEnum::LEFT_HAND) {
                $offHandMf = $offHandMf->merge($rowMf);
                $offHandDamageMin += $def->weaponDamageMin;
                $offHandDamageMax += $def->weaponDamageMax;

                if ($def->profile instanceof ProfileEnum && $def->profile->allowsDualWield()) {
                    $offHandDual = true;
                }
            } else {
                $bodyMf = $bodyMf->merge($rowMf);
            }

            if ($worn === SlotEnum::HELMET) {
                $armorByZone[ZoneEnum::HEAD->value] += $def->armor;
            }

            if ($worn === SlotEnum::ARMOR) {
                $armorByZone[ZoneEnum::CHEST->value] += $def->armor;
            }

            if ($worn === SlotEnum::PANTS) {
                $armorByZone[ZoneEnum::BELLY->value] += $def->armor;
            }

            if ($worn === SlotEnum::BOOTS) {
                $armorByZone[ZoneEnum::LEGS->value] += $def->armor;
            }
        }

        $armor = $armorByZone[ZoneEnum::HEAD->value]
            + $armorByZone[ZoneEnum::CHEST->value]
            + $armorByZone[ZoneEnum::BELLY->value]
            + $armorByZone[ZoneEnum::LEGS->value];

        $shield = $normalized[SlotEnum::SHIELD->value];

        if ($shield instanceof BackpackItem && $this->rowGivesBonuses($shield)) {
            $blockSlots = 2;
        } else {
            $blockSlots = 1;
        }

        if (
            $mainHandDual
            && $offHandDual
            && $character->level >= $this->dualWieldMinLevel()
        ) {
            $attackSlots = 2;
        } else {
            $attackSlots = 1;
        }

        $mf = $bodyMf->merge($mainHandMf)->merge($offHandMf);

        return new EquippedLoadout(
            $normalized,
            $mainHandDamageMin + $offHandDamageMin,
            $mainHandDamageMax + $offHandDamageMax,
            $mainHandDamageMin,
            $mainHandDamageMax,
            $offHandDamageMin,
            $offHandDamageMax,
            $armor,
            $statBonus,
            $mf,
            $bodyMf,
            $mainHandMf,
            $offHandMf,
            $armorByZone,
            $blockSlots,
            $attackSlots,
        );
    }

    public function gearText(EquippedLoadout $loadout): string
    {
        $lines = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $row = $loadout->row($slot);

            if (! $row instanceof BackpackItem) {
                $lines[] = __('profile.gear_slot_empty', [
                    'slot' => $slot->getLabel(),
                ]);
            } elseif (! $this->rowGivesBonuses($row)) {
                $lines[] = __('profile.gear_slot_broken', [
                    'slot' => $slot->getLabel(),
                    'name' => TelegramHtml::escape($row->item_name),
                ]);
            } else {
                $lines[] = __('profile.gear_slot_item', [
                    'slot' => $slot->getLabel(),
                    'name' => TelegramHtml::escape($row->item_name),
                    'bonus' => $this->slotBonusText($slot, $row),
                ]);
            }
        }

        $lines[] = '';
        $lines[] = __('profile.gear_totals', [
            'damage' => $this->damageRangeText($loadout->weaponDamageMin, $loadout->weaponDamageMax),
            'armor' => $loadout->armor,
            'hp' => $loadout->statBonus,
            'dodge' => $loadout->mf->dodge,
            'antiDodge' => $loadout->mf->antiDodge,
            'crit' => $loadout->mf->crit,
            'antiCrit' => $loadout->mf->antiCrit,
        ]);
        $lines[] = __('profile.gear_armor_zones', [
            'head' => $loadout->armorByZone[ZoneEnum::HEAD->value],
            'chest' => $loadout->armorByZone[ZoneEnum::CHEST->value],
            'belly' => $loadout->armorByZone[ZoneEnum::BELLY->value],
            'legs' => $loadout->armorByZone[ZoneEnum::LEGS->value],
        ]);

        return implode("\n", $lines);
    }

    public function itemCardText(EquipmentDef $def): string
    {
        if ($def->slot instanceof SlotEnum) {
            $slotLabel = $def->slot->getLabel();
        } else {
            $slotLabel = '—';
        }

        if ($def->profile instanceof ProfileEnum) {
            $profileLabel = $def->profile->getLabel();
        } else {
            $profileLabel = '—';
        }

        $lines = [
            $def->itemName,
            __('profile.item_card_meta', [
                'slot' => $slotLabel,
                'profile' => $profileLabel,
            ]),
        ];

        if ($def->description !== null && $def->description !== '') {
            $lines[] = $def->description;
        }

        $statLines = $this->itemCardStatLines($def);

        foreach ($statLines as $statLine) {
            $lines[] = $statLine;
        }

        $reqLines = $this->itemCardRequirementLines($def);

        foreach ($reqLines as $reqLine) {
            $lines[] = $reqLine;
        }

        return implode("\n", $lines);
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

    public function dropUnmetEquipped(Character $character): Character
    {
        $equipped = BackpackItem::query()
            ->where('tg_id', $character->tg_id)
            ->equipped()
            ->orderBy('id')
            ->get();

        foreach ($equipped as $row) {
            if (! $this->shop->hasItem($row->catalog_id)) {
                $res = $this->unequip($character, $row->id);

                if ($res->character instanceof Character) {
                    $character = $res->character;
                }

                continue;
            }

            $def = $this->shop->findItem($row->catalog_id);

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
        $row = BackpackItem::query()
            ->where('id', $inventoryRowId)
            ->where('tg_id', $character->tg_id)
            ->first();

        if ($row === null) {
            return ActionResult::fail(__('errors.item_not_found'));
        }

        if (! $this->shop->isEquippable($row->catalog_id)) {
            return ActionResult::fail(__('errors.cannot_equip'));
        }

        $def = $this->shop->findItem($row->catalog_id);

        if (! $def->slot instanceof SlotEnum) {
            return ActionResult::fail(__('errors.cannot_equip'));
        }

        return $this->equipToSlot($character, $inventoryRowId, $def->slot);
    }

    public function equipByItemId(Character $character, string $itemId): ActionResult
    {
        if (! $this->backpack()->owns($character->tg_id, $itemId)) {
            return ActionResult::fail(__('errors.not_in_inventory'));
        }

        $row = $this->backpack()->findOwned($character->tg_id, $itemId);

        return $this->equip($character, $row->id);
    }

    public function equipToSlot(Character $character, int $inventoryRowId, SlotEnum $slot): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId, $slot): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if (! $this->shop->isEquippable($row->catalog_id)) {
                return ActionResult::fail(__('errors.cannot_equip'));
            }

            $def = $this->shop->findItem($row->catalog_id);

            $slotError = $this->equipSlotError($character, $def, $slot);

            if ($slotError !== null) {
                return ActionResult::fail($slotError);
            }

            $reqError = $this->requirementError($character, $def);

            if ($reqError !== null) {
                return ActionResult::fail($reqError);
            }

            $oldCap = $this->characters()->maxHp($character);

            $row->slot = $slot;
            $row->save();

            $this->clearLoadoutSlot($character->tg_id, $slot);

            if ($slot === SlotEnum::SHIELD) {
                $this->clearLoadoutSlot($character->tg_id, SlotEnum::LEFT_HAND);
            }

            if ($slot === SlotEnum::LEFT_HAND) {
                $this->clearLoadoutSlot($character->tg_id, SlotEnum::SHIELD);
            }

            if (
                $slot === SlotEnum::RIGHT_HAND
                && $def->profile instanceof ProfileEnum
                && $def->profile->isSingleHandWeapon()
            ) {
                $this->clearLoadoutSlot($character->tg_id, SlotEnum::LEFT_HAND);
            }

            LoadoutSlot::query()
                ->where('backpack_item_id', $row->id)
                ->delete();

            $loadout = new LoadoutSlot;
            $loadout->tg_id = $character->tg_id;
            $loadout->slot = $slot;
            $loadout->backpack_item_id = $row->id;
            $loadout->save();

            $character = $this->characters()->findByTgId($character->tg_id);
            $newCap = $this->characters()->maxHp($character);

            if ($newCap > $oldCap) {
                $character->current_hp = $this->characters()->clampHp(
                    $character->current_hp + ($newCap - $oldCap),
                    $newCap,
                );
            } else {
                $character->current_hp = $this->characters()->clampHp(
                    $character->current_hp,
                    $newCap,
                );
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    /**
     * @return list<SlotEnum>
     */
    public function equippableSlots(Character $character, BackpackItem $row): array
    {
        if (! $this->shop->isEquippable($row->catalog_id)) {
            return [];
        }

        $def = $this->shop->findItem($row->catalog_id);
        $slots = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            if ($this->fitsEquipSlot($character, $def, $slot)) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    public function fitsEquipSlot(Character $character, EquipmentDef $def, SlotEnum $slot): bool
    {
        return $this->equipSlotError($character, $def, $slot) === null;
    }

    public function unequip(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if (! $row->isEquipped()) {
                return ActionResult::fail(__('errors.not_equipped'));
            }

            if ($this->backpack()->isFull($character)) {
                return ActionResult::fail(__('errors.inventory_full'));
            }

            LoadoutSlot::query()
                ->where('backpack_item_id', $row->id)
                ->delete();

            $character = $this->characters()->findByTgId($character->tg_id);
            $newCap = $this->characters()->maxHp($character);
            $character->current_hp = $this->characters()->clampHp(
                $character->current_hp,
                $newCap,
            );
            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function unequipSlot(Character $character, SlotEnum $slot): ActionResult
    {
        $loadout = LoadoutSlot::query()
            ->where('tg_id', $character->tg_id)
            ->where('slot', $slot->value)
            ->first();

        if ($loadout === null) {
            return ActionResult::fail(__('errors.slot_empty'));
        }

        return $this->unequip($character, $loadout->backpack_item_id);
    }

    /**
     * @return list<string>
     */
    private function itemCardRequirementLines(EquipmentDef $def): array
    {
        $lines = [];

        if ($def->reqLevel !== null) {
            $lines[] = __('profile.item_card_req_level', ['value' => $def->reqLevel]);
        }

        if ($def->reqStrength !== null) {
            $lines[] = __('profile.item_card_req_strength', ['value' => $def->reqStrength]);
        }

        if ($def->reqAgility !== null) {
            $lines[] = __('profile.item_card_req_agility', ['value' => $def->reqAgility]);
        }

        if ($def->reqInstinct !== null) {
            $lines[] = __('profile.item_card_req_instinct', ['value' => $def->reqInstinct]);
        }

        if ($def->reqVitality !== null) {
            $lines[] = __('profile.item_card_req_vitality', ['value' => $def->reqVitality]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function itemCardStatLines(EquipmentDef $def): array
    {
        $lines = [];

        if ($def->weaponDamageMax > 0) {
            $lines[] = __('profile.item_card_damage', [
                'value' => $this->damageRangeText($def->weaponDamageMin, $def->weaponDamageMax),
            ]);
        }

        if ($def->armor > 0) {
            $lines[] = __('profile.item_card_armor', ['value' => $def->armor]);
        }

        if ($def->statBonus > 0) {
            $lines[] = __('profile.item_card_hp', ['value' => $def->statBonus]);
        }

        $mfParts = [];

        if ($def->mf->dodge > 0) {
            $mfParts[] = __('profile.gear_bonus_mf_dodge', ['value' => $def->mf->dodge]);
        }

        if ($def->mf->antiDodge > 0) {
            $mfParts[] = __('profile.gear_bonus_mf_anti_dodge', ['value' => $def->mf->antiDodge]);
        }

        if ($def->mf->crit > 0) {
            $mfParts[] = __('profile.gear_bonus_mf_crit', ['value' => $def->mf->crit]);
        }

        if ($def->mf->antiCrit > 0) {
            $mfParts[] = __('profile.gear_bonus_mf_anti_crit', ['value' => $def->mf->antiCrit]);
        }

        if ($mfParts !== []) {
            $lines[] = __('profile.item_card_mf', ['value' => implode(', ', $mfParts)]);
        }

        return $lines;
    }

    private function damageRangeText(int $min, int $max): string
    {
        if ($min === $max) {
            return (string) $min;
        }

        return $min . '–' . $max;
    }

    private function defFitsWornSlot(EquipmentDef $def, SlotEnum $worn): bool
    {
        if ($def->profile instanceof ProfileEnum && $def->profile->allowsDualWield()) {
            return $worn === SlotEnum::RIGHT_HAND || $worn === SlotEnum::LEFT_HAND;
        }

        return $def->slot === $worn;
    }

    private function dualWieldMinLevel(): int
    {
        $combat = $this->config->combat();

        if (! array_key_exists('dualWieldMinLevel', $combat) || ! is_int($combat['dualWieldMinLevel'])) {
            throw new RuntimeException('settings.combat.dualWieldMinLevel missing.');
        }

        if ($combat['dualWieldMinLevel'] < 0) {
            throw new RuntimeException('settings.combat.dualWieldMinLevel must be >= 0.');
        }

        return $combat['dualWieldMinLevel'];
    }

    private function rowGivesBonuses(BackpackItem $row): bool
    {
        if ($row->max_durability === null) {
            return true;
        }

        if ($row->durability === null) {
            return true;
        }

        return $row->durability > 0;
    }

    private function slotBonusText(SlotEnum $slot, BackpackItem $row): string
    {
        $def = $this->shop->findItem($row->catalog_id);
        $mf = $def->mf->merge($this->bag->mfFromSocketed($row));
        $parts = [];

        if ($slot === SlotEnum::RIGHT_HAND || $slot === SlotEnum::LEFT_HAND) {
            if ($def->weaponDamageMax > 0) {
                $parts[] = __('profile.gear_bonus_damage', [
                    'value' => $this->damageRangeText($def->weaponDamageMin, $def->weaponDamageMax),
                ]);
            }
        } else {
            if ($def->statBonus > 0) {
                $parts[] = __('profile.gear_bonus_hp', ['value' => $def->statBonus]);
            }

            if ($def->armor > 0) {
                $parts[] = __('profile.gear_bonus_armor', ['value' => $def->armor]);
            }
        }

        if ($mf->dodge > 0) {
            $parts[] = __('profile.gear_bonus_mf_dodge', ['value' => $mf->dodge]);
        }

        if ($mf->antiDodge > 0) {
            $parts[] = __('profile.gear_bonus_mf_anti_dodge', ['value' => $mf->antiDodge]);
        }

        if ($mf->crit > 0) {
            $parts[] = __('profile.gear_bonus_mf_crit', ['value' => $mf->crit]);
        }

        if ($mf->antiCrit > 0) {
            $parts[] = __('profile.gear_bonus_mf_anti_crit', ['value' => $mf->antiCrit]);
        }

        if ($parts === []) {
            return __('profile.gear_bonus_none');
        }

        return implode(', ', $parts);
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    private function applyFightWearWithExtra(Character $character, int $extraLoss): array
    {
        return DB::transaction(function () use ($character, $extraLoss): array {
            $brokenNames = [];

            $equipped = BackpackItem::query()
                ->where('tg_id', $character->tg_id)
                ->equipped()
                ->orderBy('id')
                ->get();

            foreach ($equipped as $row) {
                if ($row->max_durability === null || $row->durability === null) {
                    continue;
                }

                if ($row->durability <= 0) {
                    continue;
                }

                if (! $this->shop->hasItem($row->catalog_id)) {
                    continue;
                }

                $def = $this->shop->findItem($row->catalog_id);

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

            $character = $this->characters()->findByTgId($character->tg_id);
            $newCap = $this->characters()->maxHp($character);
            $character->current_hp = $this->characters()->clampHp($character->current_hp, $newCap);
            $character->save();

            return $brokenNames;
        });
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

    private function clearLoadoutSlot(int $tgId, SlotEnum $slot): void
    {
        LoadoutSlot::query()
            ->where('tg_id', $tgId)
            ->where('slot', $slot->value)
            ->delete();
    }

    private function equippedRightHandProfile(Character $character): ?ProfileEnum
    {
        $loadout = LoadoutSlot::query()
            ->where('tg_id', $character->tg_id)
            ->where('slot', SlotEnum::RIGHT_HAND->value)
            ->with('backpackItem')
            ->first();

        if ($loadout === null || ! $loadout->backpackItem instanceof BackpackItem) {
            return null;
        }

        $row = $loadout->backpackItem;

        if (! $this->shop->hasItem($row->catalog_id)) {
            return null;
        }

        $def = $this->shop->findItem($row->catalog_id);

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

    /**
     * @param  array<string, scalar>  $replace
     */
    private function equipLang(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        if (! is_string($text)) {
            throw new RuntimeException($key . ' must be a string.');
        }

        return $text;
    }

    /**
     * @param  list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}>  $lines
     *
     * @param-out  list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}>  $lines
     */
    private function appendLoadoutStatChangeLines(
        array &$lines,
        Character $character,
        EquippedLoadout $before,
        EquippedLoadout $after,
    ): void {
        $beforeHp = $character->max_hp + $before->statBonus;
        $afterHp = $character->max_hp + $after->statBonus;
        $this->pushStatChangeLine($lines, 'admin.actions.equip.stat_max_hp', $beforeHp, $afterHp);

        $beforeDamage = $this->damageRangeText($before->weaponDamageMin, $before->weaponDamageMax);
        $afterDamage = $this->damageRangeText($after->weaponDamageMin, $after->weaponDamageMax);

        if ($beforeDamage !== $afterDamage) {
            $lines[] = [
                'kind' => 'plain',
                'label' => $this->equipLang('admin.actions.equip.stat_damage'),
                'before' => $beforeDamage,
                'after' => $afterDamage,
            ];
        }

        $this->pushStatChangeLine($lines, 'admin.actions.equip.stat_armor', $before->armor, $after->armor);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_dodge', $before->mf->dodge, $after->mf->dodge);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_anti_dodge', $before->mf->antiDodge, $after->mf->antiDodge);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_crit', $before->mf->crit, $after->mf->crit);
        $this->pushStatChangeLine($lines, 'admin.labels.mf_anti_crit', $before->mf->antiCrit, $after->mf->antiCrit);
    }

    /**
     * @param  list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}>  $lines
     *
     * @param-out  list<array{kind: 'note'|'plain'|'delta', text?: string, label?: string, before?: string, after?: string, delta?: int}>  $lines
     */
    private function pushStatChangeLine(array &$lines, string $labelKey, int $before, int $after): void
    {
        if ($before === $after) {
            return;
        }

        $lines[] = [
            'kind' => 'delta',
            'label' => $this->equipLang($labelKey),
            'before' => (string) $before,
            'after' => (string) $after,
            'delta' => $after - $before,
        ];
    }

    private function characters(): CharacterService
    {
        return app(CharacterService::class);
    }

    private function backpack(): BackpackService
    {
        return app(BackpackService::class);
    }
}
