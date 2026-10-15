<?php

declare(strict_types=1);

namespace App\Enums\Fight;

enum FightReturnEnum: string
{
    case Forest = 'forest';
    case Swamp = 'swamp';
    case Ruins = 'ruins';
    case Port = 'port';
    case Training = 'training';
    case Fights = 'fights';
}
