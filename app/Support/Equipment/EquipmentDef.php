<?php

declare(strict_types=1);

namespace App\Support\Equipment;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Support\Mf;

final readonly class EquipmentDef
{
    public function __construct(
        public string $itemId,
        public string $itemName,
        public ?string $description,
        public TypeEnum $itemType,
        public ?SlotEnum $slot,
        public ?ProfileEnum $profile,
        public int $price,
        public CurrencyEnum $currency,
        public int $weaponDamageMin,
        public int $weaponDamageMax,
        public Mf $mf,
        public int $statBonus,
        public int $armor,
        public ?int $reqLevel,
        public ?int $reqStrength,
        public ?int $reqAgility,
        public ?int $reqInstinct,
        public ?int $reqVitality,
        public ?int $maxDurability,
        public ?int $durabilityLossPerFight,
        public bool $repairable,
        public RepairEnum $repairTier,
        public ?int $gemSlots,
    ) {}

    public function isAvailableFor(
        int $level,
        int $strength,
        int $agility,
        int $instinct,
        int $vitality,
    ): bool {
        return $this->unmetRequirement($level, $strength, $agility, $instinct, $vitality) === null;
    }

    /**
     * @return 'level'|'strength'|'agility'|'instinct'|'vitality'|null
     */
    public function unmetRequirement(
        int $level,
        int $strength,
        int $agility,
        int $instinct,
        int $vitality,
    ): ?string {
        if ($this->reqLevel !== null && $level < $this->reqLevel) {
            return 'level';
        }

        if ($this->reqStrength !== null && $strength < $this->reqStrength) {
            return 'strength';
        }

        if ($this->reqAgility !== null && $agility < $this->reqAgility) {
            return 'agility';
        }

        if ($this->reqInstinct !== null && $instinct < $this->reqInstinct) {
            return 'instinct';
        }

        if ($this->reqVitality !== null && $vitality < $this->reqVitality) {
            return 'vitality';
        }

        return null;
    }
}
