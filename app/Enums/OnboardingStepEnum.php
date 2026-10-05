<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OnboardingStepEnum: string implements HasColor, HasLabel
{
    case CITY = 'CITY';
    case DONE = 'DONE';
    case INTRO = 'INTRO';
    case NICK = 'NICK';
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
            self::NICK => Color::Gray,
            self::CITY => Color::Slate,
            self::INTRO => Color::Sky,
            self::TUTORIAL_FIGHT => Color::Amber,
            self::QUEST_STATS => Color::Indigo,
            self::QUEST_EQUIP => Color::Violet,
            self::QUEST_SHOP => Color::Orange,
            self::DONE => Color::Green,
        };
    }

    public function getLabel(): string
    {
        return __('admin.onboarding_steps.' . $this->value);
    }
}
