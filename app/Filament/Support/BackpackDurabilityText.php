<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Backpack\BackpackItem;

final class BackpackDurabilityText
{
    public static function format(BackpackItem $record): ?string
    {
        if ($record->durability === null || $record->max_durability === null) {
            return null;
        }

        return $record->durability . '/' . $record->max_durability;
    }
}
