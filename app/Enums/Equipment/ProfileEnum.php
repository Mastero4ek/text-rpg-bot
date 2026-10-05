<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProfileEnum: string implements HasColor, HasLabel
{
    case AXE = 'AXE';
    case CHARM = 'CHARM';
    case CLUB = 'CLUB';
    case FOCUS = 'FOCUS';
    case HAMMER = 'HAMMER';
    case HEAL = 'HEAL';
    case HEAVY = 'HEAVY';
    case KNIFE = 'KNIFE';
    case KNUCKLES = 'KNUCKLES';
    case MOBILE = 'MOBILE';
    case STAMINA = 'STAMINA';
    case SWORD = 'SWORD';
    case VITAL = 'VITAL';
    case WARD = 'WARD';

    /**
     * @return list<self>
     */
    public static function forType(TypeEnum $type): array
    {
        if ($type === TypeEnum::WEAPON) {
            return [
                self::KNUCKLES,
                self::KNIFE,
                self::AXE,
                self::HAMMER,
                self::CLUB,
                self::SWORD,
            ];
        }

        if ($type === TypeEnum::ARMOR) {
            return [self::MOBILE, self::HEAVY, self::WARD];
        }

        if ($type === TypeEnum::JEWELRY) {
            return [self::FOCUS, self::CHARM, self::VITAL];
        }

        return [self::HEAL, self::STAMINA];
    }

    public function allowsDualWield(): bool
    {
        return $this === self::KNIFE || $this === self::KNUCKLES;
    }

    public function belongsToType(TypeEnum $type): bool
    {
        return in_array($this, self::forType($type), true);
    }

    /**
     * @return array<int|string, string>
     */
    public function getColor(): array
    {
        return match ($this) {
            self::AXE, self::FOCUS => Color::Red,
            self::HAMMER, self::CLUB, self::HEAVY => Color::Amber,
            self::WARD, self::CHARM => Color::Indigo,
            self::HEAL, self::VITAL => Color::Green,
            self::KNIFE, self::KNUCKLES, self::MOBILE, self::SWORD, self::STAMINA => Color::Sky,
        };
    }

    public function getLabel(): string
    {
        return __('equipment.profiles.' . $this->value);
    }

    public function isSingleHandWeapon(): bool
    {
        return in_array($this, [self::AXE, self::HAMMER, self::CLUB, self::SWORD], true);
    }
}
