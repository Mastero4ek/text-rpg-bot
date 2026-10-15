<?php

declare(strict_types=1);

namespace App\Enums\Pvp;

enum PvpChallengeStatusEnum: string
{
    case Open = 'OPEN';
    case Accepted = 'ACCEPTED';
    case Cancelled = 'CANCELLED';
    case Expired = 'EXPIRED';
}
