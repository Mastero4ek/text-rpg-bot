<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\Equipment\TypeEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Character\CharacterService;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InventoryService
{
    public function __construct(
        private readonly ShopCatalog $shop,
        private readonly CharacterService $characters,
    ) {}

    /**
     * @return Collection<int, Inventory>
     */
    public function list(int $tgId): Collection
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->orderBy('id')
            ->get();
    }

    public function addItem(int $tgId, string $itemId): Inventory
    {
        $def = $this->shop->findItem($itemId);

        $row = new Inventory;
        $row->tg_id = $tgId;
        $row->item_id = $def->itemId;
        $row->item_name = $def->itemName;
        $row->item_type = $def->itemType;
        $row->slot = $def->itemType;
        $row->stat_bonus = $def->statBonus;

        if (! $def->profile instanceof \App\Enums\Equipment\EquipmentProfileEnum) {
            $row->profile = null;
        } else {
            $row->profile = $def->profile;
        }

        $row->durability = null;
        $row->max_durability = null;
        $row->is_equipped = false;
        $row->save();

        return $row;
    }

    public function findOwned(int $tgId, string $itemId): Inventory
    {
        $row = Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_id', $itemId)
            ->first();

        if ($row === null) {
            throw new RuntimeException("Item {$itemId} not in inventory for {$tgId}");
        }

        return $row;
    }

    public function owns(int $tgId, string $itemId): bool
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_id', $itemId)
            ->exists();
    }

    public function equip(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->item_type === TypeEnum::WEAPON) {
                Inventory::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('item_type', TypeEnum::WEAPON->value)
                    ->update(['is_equipped' => false]);

                $row->is_equipped = true;
                $row->save();
                $character->weapon_id = $row->item_id;
            } elseif ($row->item_type === TypeEnum::ARMOR) {
                Inventory::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('item_type', TypeEnum::ARMOR->value)
                    ->update(['is_equipped' => false]);

                $row->is_equipped = true;
                $row->save();

                $oldCap = $this->characters->maxHp($character);
                $character->armor_id = $row->item_id;
                $newCap = $this->characters->maxHp($character);

                if ($newCap > $oldCap) {
                    $character->current_hp = $this->characters->clampHp(
                        $character->current_hp + ($newCap - $oldCap),
                        $newCap,
                    );
                } else {
                    $character->current_hp = $this->characters->clampHp(
                        $character->current_hp,
                        $newCap,
                    );
                }
            } else {
                return ActionResult::fail(__('errors.cannot_equip'));
            }

            $character->save();

            return ActionResult::okWithItem($character, $row);
        });
    }

    public function equipByItemId(Character $character, string $itemId): ActionResult
    {
        if (! $this->owns($character->tg_id, $itemId)) {
            return ActionResult::fail(__('errors.not_in_inventory'));
        }

        $row = $this->findOwned($character->tg_id, $itemId);

        return $this->equip($character, $row->id);
    }

    /**
     * @param  Collection<int, Inventory>|list<Inventory>  $rows
     */
    public function inventoryText(iterable $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            if ($row->is_equipped) {
                $mark = '✅';
            } else {
                $mark = '·';
            }

            $lines[] = __('profile.inventory_row', [
                'mark' => $mark,
                'id' => $row->id,
                'name' => $row->item_name,
            ]);
        }

        if ($lines === []) {
            return __('profile.inventory_empty');
        }

        return implode("\n", $lines);
    }
}
