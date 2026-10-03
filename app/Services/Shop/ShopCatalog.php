<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\Equipment\EffectTypeEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
use App\Support\Game\EquipmentCatalogRow;
use App\Support\Game\EquipmentDef;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class ShopCatalog
{
    private const string CACHE_KEY = 'equipment.catalog.v5';

    private const string STARTER_ARMOR_ID = 'mail_shirt';

    private const string TRAINER_WEAPON_ID = 'train_club';

    /**
     * Onboarding shop weapons — fixed set, not a DB flag.
     *
     * @var list<string>
     */
    private const array NOVICE_WEAPON_IDS = [
        'train_axe',
        'train_club',
        'train_knife',
    ];

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function potionPrice(): int
    {
        return $this->shopPotion()->price;
    }

    public function shopPotionId(): string
    {
        return $this->shopPotion()->itemId;
    }

    public function isShopWeapon(string $itemId): bool
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId !== $itemId) {
                continue;
            }

            if (! $row->enabled) {
                return false;
            }

            if ($row->def->itemType !== TypeEnum::WEAPON) {
                return false;
            }

            if (self::isNoviceWeaponId($itemId)) {
                return true;
            }

            return $row->inShop;
        }

        return false;
    }

    public function potionHeal(): int
    {
        $potion = $this->shopPotion();

        if ($potion->effectType !== EffectTypeEnum::HEAL_HP) {
            throw new RuntimeException('Shop potion effect_type must be HEAL_HP.');
        }

        if ($potion->effectValue === null) {
            throw new RuntimeException('Shop potion effect_value missing.');
        }

        return $potion->effectValue;
    }

    public function mailShirtId(): string
    {
        return self::STARTER_ARMOR_ID;
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

    public function findItem(string $itemId): EquipmentDef
    {
        foreach ($this->cachedRows() as $row) {
            if ($row->def->itemId === $itemId) {
                return $row->def;
            }
        }

        $equipment = Equipment::withTrashed()->where('item_id', $itemId)->first();

        if ($equipment === null) {
            throw new RuntimeException("Unknown item {$itemId}");
        }

        return $equipment->toEquipmentDef();
    }

    public function hasItem(string $itemId): bool
    {
        try {
            $this->findItem($itemId);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    public function mailShirt(): EquipmentDef
    {
        return $this->findItem(self::STARTER_ARMOR_ID);
    }

    private static function isNoviceWeaponId(string $itemId): bool
    {
        return in_array($itemId, self::NOVICE_WEAPON_IDS, true);
    }

    private function shopPotion(): EquipmentDef
    {
        foreach ($this->cachedRows() as $row) {
            if (! $row->enabled) {
                continue;
            }

            if (! $row->inShop) {
                continue;
            }

            if ($row->def->itemType !== TypeEnum::POTION) {
                continue;
            }

            return $row->def;
        }

        throw new RuntimeException('Shop potion missing in equipment catalog.');
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
                Equipment::query()
                    ->orderBy('sort_order')
                    ->orderBy('item_id')
                    ->get() as $equipment
            ) {
                $rows->put($equipment->item_id, new EquipmentCatalogRow(
                    $equipment->toEquipmentDef(),
                    $equipment->enabled,
                    $equipment->in_shop,
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
