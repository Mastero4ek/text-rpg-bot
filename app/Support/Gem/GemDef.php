<?php

declare(strict_types=1);

namespace App\Support\Gem;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Support\Mf;

final readonly class GemDef
{
    public function __construct(
        public string $id,
        public GemTypeEnum $type,
        public string $name,
        public ?string $description,
        public int $price,
        public CurrencyEnum $currency,
        public bool $inShop,
        public bool $enabled,
        public int $maxDurability,
        public Mf $mf,
    ) {}
}
