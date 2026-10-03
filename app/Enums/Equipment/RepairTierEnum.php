<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RepairTierEnum: string implements HasColor, HasLabel
{
    case NORMAL = 'NORMAL';
    case VIP = 'VIP';

    public function getLabel(): string
    {
        return __('equipment.repair_tiers.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NORMAL => 'gray',
            self::VIP => 'warning',
        };
    }
}
