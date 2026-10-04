<?php

declare(strict_types=1);

namespace App\Enums\Gem;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GemTypeEnum: string implements HasColor, HasLabel
{
    case DIAMOND = 'DIAMOND';
    case EMERALD = 'EMERALD';
    case RUBY = 'RUBY';
    case SAPPHIRE = 'SAPPHIRE';

    public function getLabel(): string
    {
        return __('gems.types.' . $this->value);
    }

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::DIAMOND => Color::Gray,
            self::EMERALD => Color::Green,
            self::RUBY => Color::Red,
            self::SAPPHIRE => Color::Sky,
        };
    }

    public function mfColumn(): string
    {
        return match ($this) {
            self::DIAMOND => 'mf_anti_dodge',
            self::EMERALD => 'mf_dodge',
            self::RUBY => 'mf_crit',
            self::SAPPHIRE => 'mf_anti_crit',
        };
    }
}
