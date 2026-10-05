<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Inventory;

final class InventoryDurabilityText
{
    public static function format(Inventory $record): ?string
    {
        if ($record->durability === null || $record->max_durability === null) {
            return null;
        }

        return $record->durability . '/' . $record->max_durability;
    }
}
