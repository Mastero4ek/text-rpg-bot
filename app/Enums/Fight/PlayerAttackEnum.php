<?php

declare(strict_types=1);

namespace App\Enums\Fight;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PlayerAttackEnum: string implements HasColor, HasLabel
{
    case BELLY = 'BELLY';
    case CHEST = 'CHEST';
    case HEAD = 'HEAD';
    case LEGS = 'LEGS';
    case POTION = 'POTION';

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
            self::POTION => Color::Purple,
        };
    }

    public function getLabel(): string
    {
        if ($this === self::POTION) {
            return __('combat.attack_choice.POTION');
        }

        return __('combat.zone_label.' . $this->value);
    }
}
