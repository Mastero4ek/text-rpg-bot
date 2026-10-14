<?php

declare(strict_types=1);

namespace App\Enums\LuckyBarrel;

enum LuckyBarrelStatusEnum: string
{
    case InProgress = 'IN_PROGRESS';
    case Finished = 'FINISHED';
}
