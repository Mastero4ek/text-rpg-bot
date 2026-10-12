<?php

declare(strict_types=1);

namespace App\Enums;

enum NpcEnum: string
{
    case Overseer = 'overseer';
    case Healer = 'healer';
    case Blacksmith = 'blacksmith';
    case Buyer = 'buyer';
    case Bruiser = 'bruiser';
}
