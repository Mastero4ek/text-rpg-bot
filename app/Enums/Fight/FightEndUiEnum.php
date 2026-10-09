<?php

declare(strict_types=1);

namespace App\Enums\Fight;

enum FightEndUiEnum: string
{
    case None = 'none';
    case MainMenu = 'main_menu';
    case BackToCity = 'back_to_city';
    case StatsQuest = 'stats_quest';
    case Intro = 'intro';
}
