<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\Economy\CurrencyEnum;

final readonly class GemDef
{
    public function __construct(
        public string $id,
        public string $type,
        public string $name,
        public int $price,
        public CurrencyEnum $currency,
        public bool $inShop,
        public Mf $mf,
    ) {}
}
