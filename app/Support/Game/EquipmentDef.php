<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\EffectTypeEnum;
use App\Enums\Equipment\EquipmentProfileEnum;
use App\Enums\Equipment\TypeEnum;

final readonly class EquipmentDef
{
    public function __construct(
        public string $itemId,
        public string $itemName,
        public TypeEnum $itemType,
        public ?EquipmentProfileEnum $profile,
        public int $price,
        public CurrencyEnum $currency,
        public int $weaponDamage,
        public Mf $mf,
        public int $statBonus,
        public int $armor,
        public ?EffectTypeEnum $effectType,
        public ?int $effectValue,
    ) {}
}
