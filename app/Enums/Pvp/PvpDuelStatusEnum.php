<?php

declare(strict_types=1);

namespace App\Enums\Pvp;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PvpDuelStatusEnum: string implements HasColor, HasLabel
{
    case Active = 'ACTIVE';
    case Done = 'DONE';
    case Cancelled = 'CANCELLED';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::Active => Color::Amber,
            self::Done => Color::Gray,
            self::Cancelled => Color::Red,
        };
    }

    public function getLabel(): string
    {
        return __('admin.pvp_duel_status.' . $this->value);
    }
}
