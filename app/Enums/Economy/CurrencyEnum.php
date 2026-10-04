<?php

declare(strict_types=1);

namespace App\Enums\Economy;

use Filament\Support\Colors\Color;
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

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::GOLD => Color::Amber,
            self::SILVER => Color::Gray,
        };
    }

    public function telegramMark(): string
    {
        return match ($this) {
            self::GOLD => '🥇',
            self::SILVER => '🪙',
        };
    }
}
