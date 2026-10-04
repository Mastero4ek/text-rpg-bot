<?php

declare(strict_types=1);

namespace App\Enums\Combat;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ZoneEnum: string implements HasColor, HasLabel
{
    case HEAD = 'HEAD';
    case CHEST = 'CHEST';
    case BELLY = 'BELLY';
    case LEGS = 'LEGS';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::BELLY => Color::Amber,
            self::CHEST => Color::Sky,
            self::HEAD => Color::Red,
            self::LEGS => Color::Green,
        };
    }

    public function getLabel(): string
    {
        return __('combat.zone_label.' . $this->value);
    }
}
