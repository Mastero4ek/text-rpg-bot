<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SlotEnum: string implements HasColor, HasLabel
{
    case AMULET = 'AMULET';
    case ARMOR = 'ARMOR';
    case BELT = 'BELT';
    case BOOTS = 'BOOTS';
    case HELMET = 'HELMET';
    case LEFT_HAND = 'LEFT_HAND';
    case POCKET = 'POCKET';
    case RIGHT_HAND = 'RIGHT_HAND';
    case RING = 'RING';
    case SHIELD = 'SHIELD';

    /**
     * @return list<self>
     */
    public static function forType(TypeEnum $type): array
    {
        if ($type === TypeEnum::WEAPON) {
            return [self::RIGHT_HAND, self::LEFT_HAND];
        }

        if ($type === TypeEnum::ARMOR) {
            return [
                self::HELMET,
                self::ARMOR,
                self::BOOTS,
                self::SHIELD,
            ];
        }

        if ($type === TypeEnum::JEWELRY) {
            return [self::AMULET, self::RING];
        }

        return [self::POCKET, self::BELT];
    }

    public function belongsToType(TypeEnum $type): bool
    {
        return in_array($this, self::forType($type), true);
    }

    public function getLabel(): string
    {
        return __('equipment.slots.' . $this->value);
    }

    /**
     * @return string | array<int|string, string>
     */
    public function getColor(): string|array
    {
        return match ($this) {
            self::AMULET => Color::Purple,
            self::ARMOR => 'info',
            self::BELT => 'success',
            self::BOOTS => 'gray',
            self::HELMET => 'primary',
            self::LEFT_HAND => 'warning',
            self::POCKET => 'success',
            self::RIGHT_HAND => 'danger',
            self::RING => Color::Amber,
            self::SHIELD => 'warning',
        };
    }
}
