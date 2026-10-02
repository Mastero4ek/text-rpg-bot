<?php

declare(strict_types=1);

namespace App\Enums;

enum FightStepEnum: string
{
    case ATTACK = 'ATTACK';
    case DEFEND = 'DEFEND';
    case STANCE = 'STANCE';
}
