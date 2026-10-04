<?php

declare(strict_types=1);

namespace App\Enums\Equipment;

use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SlotEnum: string implements HasColor, HasLabel
{
    case AMULET = 'AMULET';
    case RING_1 = 'RING_1';
    case RING_2 = 'RING_2';
    case HELMET = 'HELMET';
    case ARMOR = 'ARMOR';
    case GLOVES = 'GLOVES';
    case POCKET = 'POCKET';
    case PANTS = 'PANTS';
    case BOOTS = 'BOOTS';
    case LEFT_HAND = 'LEFT_HAND';
    case RIGHT_HAND = 'RIGHT_HAND';
    case SHIELD = 'SHIELD';

    /**
     * @return list<self>
     */
    public static function forType(TypeEnum $type): array
    {
        if ($type === TypeEnum::WEAPON) {
            return [self::RIGHT_HAND];
        }

        if ($type === TypeEnum::ARMOR) {
            return [
                self::HELMET,
                self::ARMOR,
                self::PANTS,
                self::BOOTS,
                self::GLOVES,
                self::SHIELD,
            ];
        }

        if ($type === TypeEnum::JEWELRY) {
            return [self::AMULET, self::RING_1, self::RING_2];
        }

        return [self::POCKET];
    }

    /**
     * Slots players can wear in gameplay.
     *
     * @return list<self>
     */
    public static function gameplayEquipSlots(): array
    {
        return [
            self::RIGHT_HAND,
            self::LEFT_HAND,
            self::SHIELD,
            self::HELMET,
            self::ARMOR,
            self::PANTS,
            self::BOOTS,
            self::GLOVES,
            self::RING_1,
            self::RING_2,
            self::AMULET,
        ];
    }

    public static function gameplayEquipSlotPattern(): string
    {
        $parts = [];

        foreach (self::gameplayEquipSlots() as $slot) {
            $parts[] = $slot->value;
        }

        return implode('|', $parts);
    }

    public function belongsToType(TypeEnum $type): bool
    {
        return in_array($this, self::forType($type), true);
    }

    public function isGameplayEquipSlot(): bool
    {
        return in_array($this, self::gameplayEquipSlots(), true);
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
            self::BOOTS => 'gray',
            self::GLOVES => 'gray',
            self::HELMET => 'primary',
            self::LEFT_HAND => 'warning',
            self::PANTS => 'info',
            self::POCKET => 'success',
            self::RIGHT_HAND => 'danger',
            self::RING_1, self::RING_2 => Color::Amber,
            self::SHIELD => 'warning',
        };
    }
}
