<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Enums\Equipment\SlotEnum;
use App\Filament\Support\InventoryDurabilityText;
use App\Models\Character;
use App\Models\Inventory;
use App\Models\LoadoutSlot;

final class LoadoutTable
{
    /**
     * @return list<array{slot: string, item_name: string, item_type: string, durability: string}>
     */
    public static function rowsFor(Character $character): array
    {
        $bySlot = [];

        $slots = LoadoutSlot::query()
            ->where('tg_id', $character->tg_id)
            ->with('inventory')
            ->get();

        foreach ($slots as $loadoutSlot) {
            $bySlot[$loadoutSlot->slot->value] = $loadoutSlot->inventory;
        }

        $rows = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $inventory = null;

            if (array_key_exists($slot->value, $bySlot) && $bySlot[$slot->value] instanceof Inventory) {
                $inventory = $bySlot[$slot->value];
            }

            if ($inventory instanceof Inventory) {
                $typeLabel = $inventory->item_type->getLabel();

                if ($typeLabel === '') {
                    $typeLabel = $inventory->item_type->value;
                }

                $durability = InventoryDurabilityText::format($inventory);

                if ($durability === null) {
                    $durability = '-';
                }

                $rows[] = [
                    'slot' => $slot->getLabel(),
                    'item_name' => $inventory->item_name,
                    'item_type' => $typeLabel,
                    'durability' => $durability,
                ];

                continue;
            }

            $rows[] = [
                'slot' => $slot->getLabel(),
                'item_name' => '-',
                'item_type' => '-',
                'durability' => '-',
            ];
        }

        return $rows;
    }
}
