<?php

declare(strict_types=1);

namespace App\Enums\Economy;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CurrencyEnum: string implements HasColor, HasLabel
{
    case GOLD = 'GOLD';
    case SILVER = 'SILVER';

    public function getLabel(): string
    {
        return match ($this) {
            self::GOLD => __('currencies.gold'),
            self::SILVER => __('currencies.silver'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::GOLD => 'warning',
            self::SILVER => 'gray',
        };
    }
}
