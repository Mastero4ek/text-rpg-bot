<?php

declare(strict_types=1);

namespace App\Support\Equipment;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\SlotEnum;
use App\Models\Backpack\BackpackItem;
use App\Support\Mf;

final readonly class EquippedLoadout
{
    /**
     * @param  array<string, BackpackItem|null>  $rowsBySlot
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
        public Mf $bodyMf,
        public Mf $mainHandMf,
        public Mf $offHandMf,
        public array $armorByZone,
        public int $blockSlots,
        public int $attackSlots,
    ) {}

    public function armorForZone(ZoneEnum $zone): int
    {
        return $this->armorByZone[$zone->value];
    }

    public function mfForMainHandAttack(): Mf
    {
        return $this->bodyMf->merge($this->mainHandMf);
    }

    public function mfForOffHandAttack(): Mf
    {
        return $this->bodyMf->merge($this->offHandMf);
    }

    public function row(SlotEnum $slot): ?BackpackItem
    {
        if (! array_key_exists($slot->value, $this->rowsBySlot)) {
            return null;
        }

        return $this->rowsBySlot[$slot->value];
    }
}
