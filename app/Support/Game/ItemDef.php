<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\ItemTypeEnum;
use App\Enums\WeaponClassEnum;

final readonly class ItemDef
{
    public function __construct(
        public string $itemId,
        public string $itemName,
        public ItemTypeEnum $itemType,
        public ?WeaponClassEnum $weaponClass,
        public int $price,
        public int $weaponDamage,
        public Mf $mf,
        public int $statBonus,
    ) {}
}
