<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProgressStepEnum: string implements HasColor, HasLabel
{
    case SPLASH = 'SPLASH';
    case SET_NICK = 'SET_NICK';
    case SET_CITY = 'SET_CITY';
    case DONE = 'DONE';
    case INTRO = 'INTRO';
    case QUEST_EQUIP = 'QUEST_EQUIP';
    case QUEST_SHOP = 'QUEST_SHOP';
    case QUEST_STATS = 'QUEST_STATS';
    case TUTORIAL_FIGHT = 'TUTORIAL_FIGHT';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::SPLASH => Color::Zinc,
            self::SET_NICK => Color::Gray,
            self::SET_CITY => Color::Slate,
            self::DONE => Color::Green,
            self::INTRO => Color::Sky,
            self::QUEST_EQUIP => Color::Violet,
            self::QUEST_SHOP => Color::Orange,
            self::QUEST_STATS => Color::Indigo,
            self::TUTORIAL_FIGHT => Color::Amber,

        };
    }

    public function getLabel(): string
    {
        return __('admin.progress_steps.' . $this->value);
    }
}
