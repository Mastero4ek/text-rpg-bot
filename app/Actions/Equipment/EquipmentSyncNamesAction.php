<?php

declare(strict_types=1);

namespace App\Actions\Equipment;

use App\Models\Equipment;
use App\Models\Inventory;

final class EquipmentSyncNamesAction
{
    public function handle(Equipment $equipment): void
    {
        Inventory::query()
            ->where('item_id', $equipment->item_id)
            ->update(['item_name' => $equipment->name]);
    }
}
