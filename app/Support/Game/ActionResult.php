<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Models\Character;
use App\Models\Inventory;

final readonly class ActionResult
{
    private function __construct(
        public bool $ok,
        public ?string $error,
        public ?Character $character,
        public ?Inventory $item,
        public ?EquipmentDef $def,
    ) {}

    public static function fail(string $error): self
    {
        return new self(false, $error, null, null, null);
    }

    public static function ok(Character $character): self
    {
        return new self(true, null, $character, null, null);
    }

    public static function okWithDef(Character $character, EquipmentDef $def): self
    {
        return new self(true, null, $character, null, $def);
    }

    public static function okWithItem(Character $character, Inventory $item): self
    {
        return new self(true, null, $character, $item, null);
    }
}
