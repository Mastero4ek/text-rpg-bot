<?php

declare(strict_types=1);

namespace App\Enums\DailyContract;

enum DailyContractStatusEnum: string
{
    case Offered = 'offered';
    case Accepted = 'accepted';
    case Ready = 'ready';
    case Completed = 'completed';
    case Expired = 'expired';
}
