<?php

declare(strict_types=1);

namespace App\Enums\DailyContract;

enum DailyContractTypeEnum: string
{
    case Hunt = 'hunt';
    case Delivery = 'delivery';
}
