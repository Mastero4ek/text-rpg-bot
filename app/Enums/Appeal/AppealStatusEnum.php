<?php

declare(strict_types=1);

namespace App\Enums\Appeal;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppealStatusEnum: string implements HasColor, HasLabel
{
    case New = 'NEW';
    case Read = 'READ';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::New => Color::Amber,
            self::Read => Color::Sky,
            self::Closed => Color::Gray,
            self::Cancelled => Color::Red,
        };
    }

    public function getLabel(): string
    {
        return __('appeal.status.' . $this->value);
    }

    public function canCancel(): bool
    {
        return $this === self::New || $this === self::Read;
    }

    public function canClose(): bool
    {
        return $this === self::New || $this === self::Read;
    }

    public function canMarkRead(): bool
    {
        return $this === self::New;
    }
}
