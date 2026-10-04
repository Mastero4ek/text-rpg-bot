<?php

declare(strict_types=1);

namespace App\Enums;

enum FightStepEnum: string
{
    case ATTACK = 'ATTACK';
    case ATTACK_SECOND = 'ATTACK_SECOND';
    case DEFEND = 'DEFEND';
    case DEFEND_SECOND = 'DEFEND_SECOND';
    case STANCE = 'STANCE';
}
