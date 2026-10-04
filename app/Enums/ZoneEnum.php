<?php

declare(strict_types=1);

namespace App\Enums;

enum ZoneEnum: string
{
    case HEAD = 'HEAD';
    case CHEST = 'CHEST';
    case BELLY = 'BELLY';
    case LEGS = 'LEGS';
}
