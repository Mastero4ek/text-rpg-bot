<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Economy\CurrencyEnum;

final readonly class QuestItemDef
{
    public function __construct(
        public string $catalogId,
        public string $name,
        public ?string $description,
        public int $price,
        public CurrencyEnum $currency,
        public bool $enabled,
    ) {}
}
