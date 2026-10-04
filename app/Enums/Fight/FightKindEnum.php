<?php

declare(strict_types=1);

namespace App\Enums\Fight;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FightKindEnum: string implements HasColor, HasLabel
{
    case PVE = 'PVE';
    case TUTORIAL = 'TUTORIAL';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::PVE => Color::Sky,
            self::TUTORIAL => Color::Amber,
        };
    }

    public function getLabel(): string
    {
        return __('combat.kinds.' . $this->value);
    }
}
