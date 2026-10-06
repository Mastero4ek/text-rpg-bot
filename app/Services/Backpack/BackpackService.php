<?php

declare(strict_types=1);

namespace App\Services\Backpack;

use App\Enums\Equipment\TypeEnum;
use App\Models\BackpackItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use App\Services\Character\CharacterService;
use App\Services\Game\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\ActionResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BackpackService
{
    public function __construct(
        private readonly ShopCatalog $shop,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function addItem(int $tgId, string $catalogId): BackpackItem
    {
        return DB::transaction(function () use ($tgId, $catalogId): BackpackItem {
            $character = Character::query()->find($tgId);

            if ($character === null) {
                throw new RuntimeException('Character not found.');
            }

            $def = $this->shop->findItem($catalogId);

            if ($this->isFull($character)) {
                throw new RuntimeException('Backpack is full.');
            }

            $row = new BackpackItem;
            $row->tg_id = $tgId;
            $row->catalog_id = $def->itemId;
            $row->item_name = $def->itemName;
            $row->item_type = $def->itemType;
            $row->slot = $def->slot;

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

    public function canAcceptItem(Character $character): bool
    {
        return ! $this->isFull($character);
    }

    public function discard(Character $character, int $backpackItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $backpackItemId): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $backpackItemId)
                ->where('tg_id', $character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ActionResult::fail(__('errors.item_not_found'));
            }

            if ($row->isEquipped()) {
                return ActionResult::fail(__('errors.unequip_first'));
            }

            $row->delete();

            return ActionResult::ok($this->characters->findByTgId($character->tg_id));
        });
    }

    public function discardEquipped(Character $character, int $backpackItemId): ActionResult
    {
        return DB::transaction(function () use ($character, $backpackItemId): ActionResult {
            $row = BackpackItem::query()
                ->where('id', $backpackItemId)
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
                ->where('backpack_item_id', $row->id)
                ->delete();

            $row->unsetRelation('loadoutSlot');
            $row->delete();

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

    public function findOwned(int $tgId, string $catalogId): BackpackItem
    {
        $row = BackpackItem::query()
            ->where('tg_id', $tgId)
            ->where('catalog_id', $catalogId)
            ->first();

        if ($row === null) {
            throw new RuntimeException("Item {$catalogId} not in backpack for {$tgId}");
        }

        return $row;
    }

    /**
     * @param  Collection<int, BackpackItem>|list<BackpackItem>  $rows
     */
    public function backpackText(iterable $rows): string
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
     * @return Collection<int, BackpackItem>
     */
    public function list(int $tgId): Collection
    {
        return BackpackItem::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, BackpackItem>
     */
    public function listByType(int $tgId, ?TypeEnum $type): Collection
    {
        $query = BackpackItem::query()
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
        $backpack = $this->backpackSettings();

        if (! array_key_exists('maxRows', $backpack) || ! is_int($backpack['maxRows'])) {
            throw new RuntimeException('settings.backpack.maxRows missing.');
        }

        if ($backpack['maxRows'] < 1) {
            throw new RuntimeException('settings.backpack.maxRows must be >= 1.');
        }

        return $backpack['maxRows'];
    }

    public function maxRows(Character $character): int
    {
        if ($character->backpack_max_rows < 1) {
            throw new RuntimeException('Character backpack_max_rows must be >= 1.');
        }

        return $character->backpack_max_rows;
    }

    public function rowCount(int $tgId): int
    {
        return BackpackItem::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->count();
    }

    /**
     * @return Collection<int, BackpackItem>
     */
    public function sellableList(int $tgId): Collection
    {
        return BackpackItem::query()
            ->where('tg_id', $tgId)
            ->unequipped()
            ->orderBy('id')
            ->get();
    }

    public function sellPayout(BackpackItem $row): int
    {
        if (! $this->shop->hasItem($row->catalog_id)) {
            throw new RuntimeException("Catalog item {$row->catalog_id} missing for sell.");
        }

        $def = $this->shop->findItem($row->catalog_id);
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

    public function owns(int $tgId, string $catalogId): bool
    {
        return BackpackItem::query()
            ->where('tg_id', $tgId)
            ->where('catalog_id', $catalogId)
            ->exists();
    }

    public function removeOne(BackpackItem $row): ActionResult
    {
        $row->delete();

        return ActionResult::ok($this->characters->findByTgId($row->tg_id));
    }

    public function rowLabel(BackpackItem $row): string
    {
        return $row->item_name;
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
    private function backpackSettings(): array
    {
        $settings = $this->config->settings();

        if (! array_key_exists('backpack', $settings) || ! is_array($settings['backpack'])) {
            throw new RuntimeException('settings.backpack missing.');
        }

        return $settings['backpack'];
    }
}
