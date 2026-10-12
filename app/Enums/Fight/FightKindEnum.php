<?php

declare(strict_types=1);

namespace App\Enums\Fight;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FightKindEnum: string implements HasColor, HasLabel
{
    case PVE = 'PVE';
    case QUEST_OVERSEER = 'QUEST_OVERSEER';
    case QUEST_BLACKSMITH = 'QUEST_BLACKSMITH';
    case QUEST_BRUISER = 'QUEST_BRUISER';
    case QUEST_HEALER = 'QUEST_HEALER';
    case QUEST_DAILY = 'QUEST_DAILY';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::PVE => Color::Sky,
            self::QUEST_OVERSEER => Color::Amber,
            self::QUEST_BLACKSMITH => Color::Orange,
            self::QUEST_BRUISER => Color::Rose,
            self::QUEST_HEALER => Color::Lime,
            self::QUEST_DAILY => Color::Teal,
        };
    }

    public function getLabel(): string
    {
        return __('combat.kinds.' . $this->value);
    }
}
