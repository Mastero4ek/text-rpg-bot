<?php

declare(strict_types=1);

namespace App\Enums\Bag;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BagKindEnum: string implements HasColor, HasLabel
{
    case GEM = 'GEM';
    case POTION = 'POTION';

    public function getLabel(): string
    {
        return __('bag.kinds.' . $this->value);
    }

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::GEM => Color::Sky,
            self::POTION => Color::Green,
        };
    }
}
