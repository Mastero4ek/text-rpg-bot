<?php

declare(strict_types=1);

namespace App\Enums;

enum StatKeyEnum: string
{
    case AGILITY = 'AGILITY';
    case INSTINCT = 'INSTINCT';
    case STRENGTH = 'STRENGTH';
    case VITALITY = 'VITALITY';

    public function column(): string
    {
        return mb_strtolower($this->value);
    }
}
