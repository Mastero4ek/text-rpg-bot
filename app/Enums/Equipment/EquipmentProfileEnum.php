<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EquipmentProfileEnum: string implements HasColor, HasLabel
{
    case CHARM = 'CHARM';
    case CLEAVE = 'CLEAVE';
    case CRUSH = 'CRUSH';
    case FOCUS = 'FOCUS';
    case GUARD = 'GUARD';
    case HEAL = 'HEAL';
    case HEAVY = 'HEAVY';
    case LIGHT = 'LIGHT';
    case MOBILE = 'MOBILE';
    case STIM = 'STIM';
    case VITAL = 'VITAL';
    case WARD = 'WARD';

    /**
     * @return list<self>
     */
    public static function forType(TypeEnum $type): array
    {
        if ($type === TypeEnum::WEAPON) {
            return [self::LIGHT, self::CLEAVE, self::CRUSH];
        }

        if ($type === TypeEnum::ARMOR) {
            return [self::MOBILE, self::HEAVY, self::WARD];
        }

        if ($type === TypeEnum::JEWELRY) {
            return [self::FOCUS, self::CHARM, self::VITAL];
        }

        return [self::HEAL, self::STIM, self::GUARD];
    }

    public function belongsToType(TypeEnum $type): bool
    {
        return in_array($this, self::forType($type), true);
    }

    public function getLabel(): string
    {
        return __('equipment.profiles.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CLEAVE, self::STIM, self::FOCUS => 'danger',
            self::CRUSH, self::HEAVY => 'warning',
            self::GUARD, self::WARD, self::CHARM => 'primary',
            self::HEAL, self::VITAL => 'success',
            self::LIGHT, self::MOBILE => 'info',
        };
    }
}
