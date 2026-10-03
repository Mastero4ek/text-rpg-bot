<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EffectTypeEnum: string implements HasColor, HasLabel
{
    case HEAL_HP = 'HEAL_HP';

    public function getLabel(): string
    {
        return __('equipment.effect_types.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::HEAL_HP => 'success',
        };
    }
}
