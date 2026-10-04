<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\SlotEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Game\GameConfig;
use App\Services\Gem\GemService;
use App\Services\Shop\ShopCatalog;
use App\Support\Equipment\EquipmentDef;
use App\Support\Equipment\EquippedLoadout;
use App\Support\Game\Mf;
use RuntimeException;

final class LoadoutService
{
    public function __construct(
        private readonly ShopCatalog $shop,
        private readonly GemService $gems,
        private readonly GameConfig $config,
    ) {}

    public function forCharacter(Character $character): EquippedLoadout
    {
        $rowsBySlot = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $rowsBySlot[$slot->value] = null;
        }

        $equipped = Inventory::query()
            ->where('tg_id', $character->tg_id)
            ->where('is_equipped', true)
            ->orderBy('id')
            ->get();

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

        foreach ($equipped as $row) {
            if (! $row->slot instanceof SlotEnum) {
                continue;
            }

            if (! $row->slot->isGameplayEquipSlot()) {
                continue;
            }

            if (! $this->shop->hasItem($row->item_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->item_id);

            if (! $this->defFitsWornSlot($def, $row->slot)) {
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

            $rowsBySlot[$row->slot->value] = $row;

            if (! $this->rowGivesBonuses($row)) {
                continue;
            }

            $rowMf = $def->mf->merge($this->gems->mfFromSocketed($row));
            $statBonus += $def->statBonus;

            if ($row->slot === SlotEnum::RIGHT_HAND) {
                $mainHandMf = $mainHandMf->merge($rowMf);
                $mainHandDamageMin += $def->weaponDamageMin;
                $mainHandDamageMax += $def->weaponDamageMax;

                if ($def->profile instanceof ProfileEnum && $def->profile->allowsDualWield()) {
                    $mainHandDual = true;
                }
            } elseif ($row->slot === SlotEnum::LEFT_HAND) {
                $offHandMf = $offHandMf->merge($rowMf);
                $offHandDamageMin += $def->weaponDamageMin;
                $offHandDamageMax += $def->weaponDamageMax;

                if ($def->profile instanceof ProfileEnum && $def->profile->allowsDualWield()) {
                    $offHandDual = true;
                }
            } else {
                $bodyMf = $bodyMf->merge($rowMf);
            }

            if ($row->slot === SlotEnum::HELMET) {
                $armorByZone[ZoneEnum::HEAD->value] += $def->armor;
            }

            if ($row->slot === SlotEnum::ARMOR) {
                $armorByZone[ZoneEnum::CHEST->value] += $def->armor;
            }

            if ($row->slot === SlotEnum::PANTS) {
                $armorByZone[ZoneEnum::BELLY->value] += $def->armor;
            }

            if ($row->slot === SlotEnum::BOOTS) {
                $armorByZone[ZoneEnum::LEGS->value] += $def->armor;
            }
        }

        $armor = $armorByZone[ZoneEnum::HEAD->value]
            + $armorByZone[ZoneEnum::CHEST->value]
            + $armorByZone[ZoneEnum::BELLY->value]
            + $armorByZone[ZoneEnum::LEGS->value];

        $shield = $rowsBySlot[SlotEnum::SHIELD->value];

        if ($shield instanceof Inventory && $this->rowGivesBonuses($shield)) {
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
            $rowsBySlot,
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

            if (! $row instanceof Inventory) {
                $lines[] = __('profile.gear_slot_empty', [
                    'slot' => $slot->getLabel(),
                ]);
            } elseif (! $this->rowGivesBonuses($row)) {
                $lines[] = __('profile.gear_slot_broken', [
                    'slot' => $slot->getLabel(),
                    'name' => $row->item_name,
                ]);
            } else {
                $lines[] = __('profile.gear_slot_item', [
                    'slot' => $slot->getLabel(),
                    'name' => $row->item_name,
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

    private function rowGivesBonuses(Inventory $row): bool
    {
        if ($row->max_durability === null) {
            return true;
        }

        if ($row->durability === null) {
            return true;
        }

        return $row->durability > 0;
    }

    private function slotBonusText(SlotEnum $slot, Inventory $row): string
    {
        $def = $this->shop->findItem($row->item_id);
        $mf = $def->mf->merge($this->gems->mfFromSocketed($row));
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
}
