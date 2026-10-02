<?php

declare(strict_types=1);

namespace App\Enums;

enum ZoneEnum: string
{
    case BELLY = 'BELLY';
    case CHEST = 'CHEST';
    case HEAD = 'HEAD';
    case LEGS = 'LEGS';
}
