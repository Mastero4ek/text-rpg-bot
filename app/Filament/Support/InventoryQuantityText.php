<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\Equipment\TypeEnum;
use App\Models\Inventory;
use App\Services\Inventory\InventoryService;

final class InventoryQuantityText
{
    public static function format(Inventory $record): ?string
    {
        if ($record->item_type !== TypeEnum::POTION) {
            return null;
        }

        return $record->quantity . '/' . app(InventoryService::class)->potionMaxStack();
    }
}
