<?php

declare(strict_types=1);

namespace App\Support\Gem;

final readonly class GemCatalogRow
{
    public function __construct(
        public GemDef $def,
        public bool $enabled,
        public bool $inShop,
    ) {}
}
