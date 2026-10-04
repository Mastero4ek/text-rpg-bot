<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TypeEnum: string implements HasColor, HasLabel
{
    case ARMOR = 'ARMOR';
    case JEWELRY = 'JEWELRY';
    case POTION = 'POTION';
    case WEAPON = 'WEAPON';

    public function getLabel(): string
    {
        return __('equipment.types.' . $this->value);
    }

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::ARMOR => Color::Sky,
            self::JEWELRY => Color::Purple,
            self::POTION => Color::Green,
            self::WEAPON => Color::Red,
        };
    }
}
