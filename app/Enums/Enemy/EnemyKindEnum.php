<?php

declare(strict_types=1);

namespace App\Enums\Enemy;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EnemyKindEnum: string implements HasColor, HasLabel
{
    case FIXED = 'FIXED';
    case MIRROR = 'MIRROR';

    public function getLabel(): string
    {
        return __('enemy.kinds.' . $this->value);
    }

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::FIXED => Color::Amber,
            self::MIRROR => Color::Sky,
        };
    }
}
