<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Models\LoadoutSlot;
use App\Services\Character\CharacterService;
use App\Services\Game\GameConfig;
use App\Services\Gem\GemService;
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
        private readonly GameConfig $config,
        private readonly GemService $gems,
    ) {}

    public function addItem(int $tgId, string $itemId): Inventory
    {
        return DB::transaction(function () use ($tgId, $itemId): Inventory {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                throw new RuntimeException('Character not found.');
            }

            $def = $this->shop->findItem($itemId);

            if ($def->itemType === TypeEnum::POTION) {
                $stack = Inventory::query()
                    ->where('tg_id', $tgId)
                    ->where('item_id', $def->itemId)
                    ->where('item_type', TypeEnum::POTION)
                    ->where('quantity', '<', $this->potionMaxStack())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($stack instanceof Inventory) {
                    $stack->quantity += 1;
                    $stack->save();

                    return $stack;
                }
            }

            if ($this->isFull($character)) {
                throw new RuntimeException('Backpack is full.');
            }

            $row = new Inventory;
            $row->tg_id = $tgId;
            $row->item_id = $def->itemId;
            $row->item_name = $def->itemName;
            $row->item_type = $def->itemType;
            $row->slot = $def->slot;
            $row->quantity = 1;

            if ($def->maxDurability === null) {
                $row->durability = null;
                $row->max_durability = null;
            } else {
                $row->durability = $def->maxDurability;
                $row->max_durability = $def->maxDurability;
            }

            $row->save();

            return $row;
        });
    }

    public function canAcceptItem(Character $character, string $itemId): bool
    {
        $def = $this->shop->findItem($itemId);

        if ($def->itemType === TypeEnum::POTION) {
            $hasStackSpace = Inventory::query()
                ->where('tg_id', $character->tg_id)
                ->where('item_id', $def->itemId)
                ->where('item_type', TypeEnum::POTION)
                ->where('quantity', '<', $this->potionMaxStack())
                ->exists();

            if ($hasStackSpace) {
                return true;
            }
        }

        return ! $this->isFull($character);
    }

    public function consumePotion(int $tgId, ProfileEnum $profile): ActionResult
    {
        if ($profile !== ProfileEnum::HEAL && $profile !== ProfileEnum::STAMINA) {
            throw new RuntimeException('Potion profile must be HEAL or STAMINA.');
        }

        return DB::transaction(function () use ($tgId, $profile): ActionResult {
            $rows = Inventory::query()
                ->where('tg_id', $tgId)
                ->where('item_type', TypeEnum::POTION)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $match = null;

            foreach ($rows as $row) {
                $def = $this->shop->findItem($row->item_id);

                if ($def->profile !== $profile) {
                    continue;
                }

                $match = $row;

                break;
            }

            if ($match === null) {
                return ActionResult::fail(__('combat.no_potion_turn'));
            }

            $def = $this->shop->findItem($match->item_id);

            if ($def->effectValue === null) {
                throw new RuntimeException("Potion {$match->item_id} has no effect_value.");
            }

            $this->removeOneFromRow($match);

            return ActionResult::okWithDef($this->characters->findByTgId($tgId), $def);
        });
    }

    public function discard(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->isEquipped()) {
                return ActionResult::fail(__('errors.unequip_first'));
            }

            $moved = $this->removeOneFromRow($row);

            if (! $moved->ok) {
                return $moved;
            }

            $character = $this->characters->findByTgId($character->tg_id);

            return ActionResult::ok($character);
        });
    }

    public function discardEquipped(Character $character, int $inventoryRowId): ActionResult
    {
        return DB::transaction(function () use ($character, $inventoryRowId): ActionResult {
            $row = Inventory::query()
                ->where('id', $inventoryRowId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if (! $row->isEquipped()) {
                return ActionResult::fail(__('errors.not_equipped'));
            }

            LoadoutSlot::query()
                ->where('inventory_id', $row->id)
                ->delete();

            $row->unsetRelation('loadoutSlot');

            $moved = $this->removeOneFromRow($row);

            if (! $moved->ok) {
                return $moved;
            }

            $character = $this->characters->findByTgId($character->tg_id);
            $newCap = $this->characters->maxHp($character);
            $character->current_hp = $this->characters->clampHp(
                $character->current_hp,
                $newCap,
            );
            $character->save();

            return ActionResult::ok($character);
        });
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

    /**
     * @param  Collection<int, Inventory>|list<Inventory>  $rows
     */

    /**
     * @param  Collection<int, Inventory>|list<Inventory>  $rows
     */
    public function inventoryText(iterable $rows): string
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = __('profile.inventory_row', [
                'mark' => '·',
                'id' => $row->id,
                'name' => $this->rowLabel($row),
            ]);
        }

        if ($lines === []) {
            return __('profile.inventory_empty');
        }

        return implode("\n", $lines);
    }

    public function isFull(Character $character): bool
    {
        return $this->rowCount($character->tg_id) >= $this->maxRows($character);
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function list(int $tgId): Collection
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function listByType(int $tgId, ?TypeEnum $type): Collection
    {
        $query = Inventory::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->orderBy('id');

        if ($type instanceof TypeEnum) {
            $query->where('item_type', $type->value);
        }

        return $query->get();
    }

    public function defaultMaxRows(): int
    {
        $inventory = $this->inventorySettings();

        if (! array_key_exists('maxRows', $inventory) || ! is_int($inventory['maxRows'])) {
            throw new RuntimeException('settings.inventory.maxRows missing.');
        }

        if ($inventory['maxRows'] < 1) {
            throw new RuntimeException('settings.inventory.maxRows must be >= 1.');
        }

        return $inventory['maxRows'];
    }

    public function potionMaxStack(): int
    {
        $inventory = $this->inventorySettings();

        if (! array_key_exists('potionMaxStack', $inventory) || ! is_int($inventory['potionMaxStack'])) {
            throw new RuntimeException('settings.inventory.potionMaxStack missing.');
        }

        if ($inventory['potionMaxStack'] < 1) {
            throw new RuntimeException('settings.inventory.potionMaxStack must be >= 1.');
        }

        return $inventory['potionMaxStack'];
    }

    public function maxRows(Character $character): int
    {
        if ($character->inventory_max_rows < 1) {
            throw new RuntimeException('Character inventory_max_rows must be >= 1.');
        }

        return $character->inventory_max_rows;
    }

    public function rowCount(int $tgId): int
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->count();
    }

    /**
     * @return Collection<int, Inventory>
     */
    public function sellableList(int $tgId): Collection
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->orderBy('id')
            ->get();
    }

    public function sellPayout(Inventory $row): int
    {
        if (! $this->shop->hasItem($row->item_id)) {
            throw new RuntimeException("Catalog item {$row->item_id} missing for sell.");
        }

        $def = $this->shop->findItem($row->item_id);
        $base = intdiv($def->price * $this->sellRatioPermille(), 1000);

        if ($base <= 0) {
            return 0;
        }

        if ($row->max_durability === null || $row->durability === null) {
            return $base;
        }

        if ($row->max_durability <= 0) {
            return 0;
        }

        $payout = intdiv($base * $row->durability, $row->max_durability);

        if ($payout < 1 && $row->durability > 0) {
            return 1;
        }

        return $payout;
    }

    public function owns(int $tgId, string $itemId): bool
    {
        return Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_id', $itemId)
            ->exists();
    }

    public function potionCount(int $tgId): int
    {
        $total = Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_type', TypeEnum::POTION)
            ->sum('quantity');

        return (int) $total;
    }

    public function potionCountByProfile(int $tgId, ProfileEnum $profile): int
    {
        if ($profile !== ProfileEnum::HEAL && $profile !== ProfileEnum::STAMINA) {
            throw new RuntimeException('Potion profile must be HEAL or STAMINA.');
        }

        $count = 0;

        $rows = Inventory::query()
            ->where('tg_id', $tgId)
            ->where('item_type', TypeEnum::POTION)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (! $this->shop->hasItem($row->item_id)) {
                continue;
            }

            if ($this->shop->findItem($row->item_id)->profile !== $profile) {
                continue;
            }

            $count += $row->quantity;
        }

        return $count;
    }

    public function removeOne(Inventory $row): ActionResult
    {
        return $this->removeOneFromRow($row);
    }

    public function rowLabel(Inventory $row): string
    {
        if ($row->item_type !== TypeEnum::POTION) {
            return $row->item_name;
        }

        return $row->item_name . ' (' . $row->quantity . '/' . $this->potionMaxStack() . ')';
    }

    private function sellRatioPermille(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('shop', $settings) || ! is_array($settings['shop'])) {
            throw new RuntimeException('settings.shop missing.');
        }

        $shop = $settings['shop'];

        if (! array_key_exists('sellRatioPermille', $shop) || ! is_int($shop['sellRatioPermille'])) {
            throw new RuntimeException('settings.shop.sellRatioPermille missing.');
        }

        if ($shop['sellRatioPermille'] < 0 || $shop['sellRatioPermille'] > 1000) {
            throw new RuntimeException('settings.shop.sellRatioPermille must be 0..1000.');
        }

        return $shop['sellRatioPermille'];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventorySettings(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('inventory', $settings) || ! is_array($settings['inventory'])) {
            throw new RuntimeException('settings.inventory missing.');
        }

        return $settings['inventory'];
    }

    private function removeOneFromRow(Inventory $row): ActionResult
    {
        if ($row->quantity > 1) {
            $row->quantity -= 1;
            $row->save();

            return ActionResult::ok($this->characters->findByTgId($row->tg_id));
        }

        $character = $this->characters->findByTgId($row->tg_id);
        $moved = $this->gems->moveSocketedToPouch($character, $row);

        if (! $moved->ok) {
            return $moved;
        }

        $row->delete();

        return ActionResult::ok($this->characters->findByTgId($row->tg_id));
    }
}
