<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;

final readonly class Fighter
{
    /**
     * @param  array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}  $armorByZone
     */
    public function __construct(
        public string $name,
        public int $strength,
        public int $agility,
        public int $instinct,
        public int $vitality,
        public int $weaponDamage,
        public Mf $weaponMf,
        public StanceEnum $stance,
        public array $armorByZone,
        public int $stamina,
        public int $maxStamina,
    ) {}

    public function armorForZone(ZoneEnum $zone): int
    {
        return $this->armorByZone[$zone->value];
    }
}
