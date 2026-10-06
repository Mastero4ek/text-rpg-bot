<?php

declare(strict_types=1);

namespace App\Support\Bag;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;

final readonly class PotionDef
{
    public function __construct(
        public string $catalogId,
        public string $name,
        public ?string $description,
        public ProfileEnum $profile,
        public int $effectValue,
        public int $price,
        public CurrencyEnum $currency,
        public bool $inShop,
        public bool $enabled,
    ) {}
}
