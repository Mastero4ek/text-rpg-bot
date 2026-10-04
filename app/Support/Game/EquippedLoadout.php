<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\Equipment\SlotEnum;
use App\Enums\ZoneEnum;
use App\Models\Inventory;

final readonly class EquippedLoadout
{
    /**
     * @param  array<string, Inventory|null>  $rowsBySlot
     * @param  array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}  $armorByZone
     */
    public function __construct(
        public array $rowsBySlot,
        public int $weaponDamageMin,
        public int $weaponDamageMax,
        public int $mainHandDamageMin,
        public int $mainHandDamageMax,
        public int $offHandDamageMin,
        public int $offHandDamageMax,
        public int $armor,
        public int $statBonus,
        public Mf $mf,
        public array $armorByZone,
        public int $blockSlots,
        public int $attackSlots,
    ) {}

    public function armorForZone(ZoneEnum $zone): int
    {
        return $this->armorByZone[$zone->value];
    }

    public function row(SlotEnum $slot): ?Inventory
    {
        if (! array_key_exists($slot->value, $this->rowsBySlot)) {
            return null;
        }

        return $this->rowsBySlot[$slot->value];
    }
}
