<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingStepEnum: string
{
    case CITY = 'CITY';
    case DONE = 'DONE';
    case INTRO = 'INTRO';
    case NICK = 'NICK';
    case QUEST_EQUIP = 'QUEST_EQUIP';
    case QUEST_SHOP = 'QUEST_SHOP';
    case QUEST_STATS = 'QUEST_STATS';
    case TUTORIAL_FIGHT = 'TUTORIAL_FIGHT';
}
