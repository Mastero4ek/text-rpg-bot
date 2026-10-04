<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RepairEnum: string implements HasColor, HasLabel
{
    case NORMAL = 'NORMAL';
    case VIP = 'VIP';

    public function getLabel(): string
    {
        return __('equipment.repair_tiers.' . $this->value);
    }

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::NORMAL => Color::Gray,
            self::VIP => Color::Amber,
        };
    }
}
