<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Support\Equipment\EquipmentCatalogRow;
use App\Support\Equipment\EquipmentDef;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class ShopCatalog
{
    private const string CACHE_KEY = 'backpack.catalog.v1';

    private const string STARTER_ARMOR_ID = 'heavy_0';

    private const string STARTER_KNUCKLES_ID = 'knuckles_0';

    private const string TRAINER_WEAPON_ID = 'club_0';

    /**
     * Onboarding shop weapons — fixed set, not a DB flag.
     *
     * @var list<string>
     */
    private const array NOVICE_WEAPON_IDS = [
        'axe_0',
        'club_0',
        'knife_0',
    ];

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function isShopWeapon(string $catalogId): bool
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId !== $catalogId) {
                continue;
            }

            if (! $row->enabled) {
                return false;
            }

            if ($row->def->itemType !== TypeEnum::WEAPON) {
                return false;
            }

            if (self::isNoviceWeaponId($catalogId)) {
                return true;
            }

            return $row->inShop;
        }

        return false;
    }

    public function isShopGear(string $catalogId): bool
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId !== $catalogId) {
                continue;
            }

            if (! $row->enabled) {
                return false;
            }

            if (! $row->inShop) {
                return false;
            }

            if ($row->def->itemType !== TypeEnum::ARMOR) {
                return false;
            }

            if (! $row->def->slot instanceof SlotEnum) {
                return false;
            }

            return in_array($row->def->slot, $this->shopArmorSlots(), true);
        }

        return false;
    }

    public function isShopJewelry(string $catalogId): bool
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId !== $catalogId) {
                continue;
            }

            if (! $row->enabled) {
                return false;
            }

            if (! $row->inShop) {
                return false;
            }

            if ($row->def->itemType !== TypeEnum::JEWELRY) {
                return false;
            }

            if (! $row->def->slot instanceof SlotEnum) {
                return false;
            }

            return $row->def->slot->isGameplayEquipSlot();
        }

        return false;
    }

    public function isShopMerchandise(string $catalogId): bool
    {
        if ($this->isShopWeapon($catalogId)) {
            return true;
        }

        if ($this->isShopGear($catalogId)) {
            return true;
        }

        return $this->isShopJewelry($catalogId);
    }

    public function isEquippable(string $catalogId): bool
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId !== $catalogId) {
                continue;
            }

            if (! $row->enabled) {
                return false;
            }

            if (! $row->def->slot instanceof SlotEnum) {
                return false;
            }

            return $row->def->slot->isGameplayEquipSlot();
        }

        return false;
    }

    /**
     * @return list<EquipmentDef>
     */
    public function shopGear(): array
    {
        $items = [];

        foreach ($this->cachedRows() as $row) {
            if (! $row->enabled) {
                continue;
            }

            if (! $row->inShop) {
                continue;
            }

            if ($row->def->itemType !== TypeEnum::ARMOR) {
                continue;
            }

            if (! $row->def->slot instanceof SlotEnum) {
                continue;
            }

            if (! in_array($row->def->slot, $this->shopArmorSlots(), true)) {
                continue;
            }

            $items[] = $row->def;
        }

        return $this->sortedDefs($items);
    }

    /**
     * @return list<EquipmentDef>
     */
    public function shopJewelry(): array
    {
        $items = [];

        foreach ($this->cachedRows() as $row) {
            if (! $row->enabled) {
                continue;
            }

            if (! $row->inShop) {
                continue;
            }

            if ($row->def->itemType !== TypeEnum::JEWELRY) {
                continue;
            }

            if (! $row->def->slot instanceof SlotEnum) {
                continue;
            }

            if (! $row->def->slot->isGameplayEquipSlot()) {
                continue;
            }

            $items[] = $row->def;
        }

        return $this->sortedDefs($items);
    }

    public function mailShirtId(): string
    {
        return self::STARTER_ARMOR_ID;
    }

    public function starterKnucklesId(): string
    {
        return self::STARTER_KNUCKLES_ID;
    }

    public function freeTrainerItemId(): string
    {
        return self::TRAINER_WEAPON_ID;
    }

    /**
     * @return list<EquipmentDef>
     */
    public function noviceWeapons(): array
    {
        $items = [];

        foreach ($this->cachedRows() as $row) {
            if (! $row->enabled) {
                continue;
            }

            if ($row->def->itemType !== TypeEnum::WEAPON) {
                continue;
            }

            if (! self::isNoviceWeaponId($row->def->itemId)) {
                continue;
            }

            $items[] = $row->def;
        }

        return $this->sortedDefs($items);
    }

    /**
     * @return list<EquipmentDef>
     */
    public function tierWeapons(): array
    {
        $items = [];

        foreach ($this->cachedRows() as $row) {
            if (! $row->enabled) {
                continue;
            }

            if (! $row->inShop) {
                continue;
            }

            if ($row->def->itemType !== TypeEnum::WEAPON) {
                continue;
            }

            if (self::isNoviceWeaponId($row->def->itemId)) {
                continue;
            }

            $items[] = $row->def;
        }

        return $this->sortedDefs($items);
    }

    /**
     * @return list<EquipmentDef>
     */
    public function weaponsForMode(string $mode): array
    {
        if ($mode === 'novice') {
            return $this->noviceWeapons();
        }

        if ($mode === 'full') {
            return $this->tierWeapons();
        }

        throw new RuntimeException("Unknown shop mode: {$mode}");
    }

    public function findItem(string $catalogId): EquipmentDef
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId === $catalogId) {
                return $row->def;
            }
        }

        $catalog = BackpackCatalog::withTrashed()->where('catalog_id', $catalogId)->first();

        if ($catalog === null) {
            throw new RuntimeException("Unknown item {$catalogId}");
        }

        return $catalog->toEquipmentDef();
    }

    public function hasItem(string $catalogId): bool
    {
        try {
            $this->findItem($catalogId);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    public function mailShirt(): EquipmentDef
    {
        return $this->findItem(self::STARTER_ARMOR_ID);
    }

    private static function isNoviceWeaponId(string $catalogId): bool
    {
        return in_array($catalogId, self::NOVICE_WEAPON_IDS, true);
    }

    /**
     * Armor slots sold in the gameplay shop (stage 1.1).
     *
     * @return list<SlotEnum>
     */
    private function shopArmorSlots(): array
    {
        return [
            SlotEnum::HELMET,
            SlotEnum::ARMOR,
            SlotEnum::PANTS,
            SlotEnum::BOOTS,
            SlotEnum::GLOVES,
            SlotEnum::SHIELD,
        ];
    }

    /**
     * @return Collection<string, EquipmentCatalogRow>
     */
    private function cachedRows(): Collection
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof Collection) {
            $first = $cached->first();

            if ($first instanceof EquipmentCatalogRow || $cached->isEmpty()) {
                /** @var Collection<string, EquipmentCatalogRow> $cached */
                return $cached;
            }
        }

        Cache::forget(self::CACHE_KEY);

        /** @var Collection<string, EquipmentCatalogRow> $rows */
        $rows = Cache::remember(self::CACHE_KEY, 3600, function (): Collection {
            $rows = new Collection;

            foreach (
                BackpackCatalog::query()
                    ->with('cities')
                    ->orderBy('sort_order')
                    ->orderBy('catalog_id')
                    ->get() as $catalog
            ) {
                $rows->put($catalog->catalog_id, new EquipmentCatalogRow(
                    $catalog->toEquipmentDef(),
                    $catalog->enabled,
                    $catalog->cities->isNotEmpty(),
                ));
            }

            return $rows;
        });

        return $rows;
    }

    /**
     * @param  list<EquipmentDef>  $items
     * @return list<EquipmentDef>
     */
    private function sortedDefs(array $items): array
    {
        usort($items, function (EquipmentDef $a, EquipmentDef $b): int {
            return $a->itemId <=> $b->itemId;
        });

        return $items;
    }
}
