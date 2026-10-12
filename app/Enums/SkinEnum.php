<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SkinEnum: string implements HasColor, HasLabel
{
    case Mobile = 'mobile';
    case Heavy = 'heavy';
    case Ward = 'ward';

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::Mobile => Color::Sky,
            self::Heavy => Color::Amber,
            self::Ward => Color::Indigo,
        };
    }

    public function getLabel(): string
    {
        return __('admin.skins.' . $this->value);
    }
}
