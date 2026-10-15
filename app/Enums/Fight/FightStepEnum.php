<?php

declare(strict_types=1);

namespace App\Enums\Fight;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FightStepEnum: string implements HasColor, HasLabel
{
    case ATTACK = 'ATTACK';
    case ATTACK_SECOND = 'ATTACK_SECOND';
    case DEFEND = 'DEFEND';
    case DEFEND_SECOND = 'DEFEND_SECOND';
    case STANCE = 'STANCE';
    case WAIT = 'WAIT';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::ATTACK => Color::Red,
            self::ATTACK_SECOND => Color::Rose,
            self::DEFEND => Color::Green,
            self::DEFEND_SECOND => Color::Emerald,
            self::STANCE => Color::Gray,
            self::WAIT => Color::Slate,
        };
    }

    public function getLabel(): string
    {
        return __('combat.steps.' . $this->value);
    }
}
