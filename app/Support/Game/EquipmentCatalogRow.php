<?php

declare(strict_types=1);

namespace App\Support\Game;

final readonly class EquipmentCatalogRow
{
    public function __construct(
        public EquipmentDef $def,
        public bool $enabled,
        public bool $inShop,
    ) {}
}
