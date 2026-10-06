<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Support\Equipment\EquipmentDef;

final readonly class ActionResult
{
    private function __construct(
        public bool $ok,
        public ?string $error,
        public ?Character $character,
        public ?BackpackItem $item,
        public ?EquipmentDef $def,
        public ?PotionDef $potion,
    ) {}

    public static function fail(string $error): self
    {
        return new self(false, $error, null, null, null, null);
    }

    public static function ok(Character $character): self
    {
        return new self(true, null, $character, null, null, null);
    }

    public static function okWithDef(Character $character, EquipmentDef $def): self
    {
        return new self(true, null, $character, null, $def, null);
    }

    public static function okWithItem(Character $character, BackpackItem $item): self
    {
        return new self(true, null, $character, $item, null, null);
    }

    public static function okWithPotion(Character $character, PotionDef $potion): self
    {
        return new self(true, null, $character, null, null, $potion);
    }
}
