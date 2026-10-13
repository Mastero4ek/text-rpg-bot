<?php

declare(strict_types=1);

namespace App\Enums\LuckyBarrel;

enum LuckyBarrelOutcomeEnum: string
{
    case Play = 'play';
    case Win = 'win';
    case WinMax = 'win_max';
    case Lose = 'lose';
    case Noop = 'noop';
    case Forfeit = 'forfeit';
}
