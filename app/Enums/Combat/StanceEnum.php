<?php

declare(strict_types=1);

namespace App\Enums\Combat;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StanceEnum: string implements HasColor, HasLabel
{
    case ATTACK = 'ATTACK';
    case DEFEND = 'DEFEND';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::ATTACK => Color::Red,
            self::DEFEND => Color::Green,
        };
    }

    public function getLabel(): string
    {
        return __('combat.stances.' . $this->value);
    }
}
