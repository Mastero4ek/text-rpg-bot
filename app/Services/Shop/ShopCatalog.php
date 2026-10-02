<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Enums\ItemTypeEnum;
use App\Enums\WeaponClassEnum;
use App\Services\Game\GameConfig;
use App\Support\Game\ItemDef;
use App\Support\Game\Mf;
use RuntimeException;

final class ShopCatalog
{
    public function __construct(
        private readonly GameConfig $config,
    ) {}

    public function potionPrice(): int
    {
        return $this->intFromShop('potionPrice');
    }

    public function mailShirtId(): string
    {
        return $this->stringFromShop('mailShirtId');
    }

    public function noviceWeaponPrice(): int
    {
        return $this->intFromShop('noviceWeaponPrice');
    }

    public function freeTrainerItemId(): string
    {
        return $this->stringFromShop('freeTrainerItemId');
    }

    /**
     * @return list<ItemDef>
     */
    public function noviceWeapons(): array
    {
        return $this->weaponList('noviceWeapons');
    }

    /**
     * @return list<ItemDef>
     */
    public function tierWeapons(): array
    {
        return $this->weaponList('tierWeapons');
    }

    /**
     * @return list<ItemDef>
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

    public function findItem(string $itemId): ItemDef
    {
        foreach ($this->noviceWeapons() as $weapon) {
            if ($weapon->itemId === $itemId) {
                return $weapon;
            }
        }

        foreach ($this->tierWeapons() as $weapon) {
            if ($weapon->itemId === $itemId) {
                return $weapon;
            }
        }

        foreach ($this->armorItems() as $armor) {
            if ($armor->itemId === $itemId) {
                return $armor;
            }
        }

        throw new RuntimeException("Unknown item {$itemId}");
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

    public function mailShirt(): ItemDef
    {
        return $this->findItem($this->mailShirtId());
    }

    public function itemName(string $itemId): string
    {
        $key = 'items.' . $itemId;
        $name = __($key);

        if ($name === $key) {
            return $itemId;
        }

        return $name;
    }

    /**
     * @return list<ItemDef>
     */
    private function armorItems(): array
    {
        $shop = $this->config->shop();

        if (! array_key_exists('armor', $shop) || ! is_array($shop['armor'])) {
            throw new RuntimeException('shop.armor missing.');
        }

        $items = [];

        foreach ($shop['armor'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Invalid armor row.');
            }

            $items[] = new ItemDef(
                $this->stringField($row, 'item_id'),
                $this->itemName($this->stringField($row, 'item_id')),
                ItemTypeEnum::ARMOR,
                null,
                $this->intField($row, 'price'),
                0,
                new Mf(0, 0, 0, 0),
                $this->intField($row, 'stat_bonus'),
            );
        }

        return $items;
    }

    /**
     * @return list<ItemDef>
     */
    private function weaponList(string $key): array
    {
        $shop = $this->config->shop();

        if (! array_key_exists($key, $shop) || ! is_array($shop[$key])) {
            throw new RuntimeException("shop.{$key} missing.");
        }

        $items = [];

        foreach ($shop[$key] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException("Invalid weapon row in {$key}.");
            }

            if (! array_key_exists('mf', $row) || ! is_array($row['mf'])) {
                throw new RuntimeException('Weapon mf missing.');
            }

            $items[] = new ItemDef(
                $this->stringField($row, 'item_id'),
                $this->itemName($this->stringField($row, 'item_id')),
                ItemTypeEnum::WEAPON,
                WeaponClassEnum::from($this->stringField($row, 'weapon_class')),
                $this->intField($row, 'price'),
                $this->intField($row, 'weaponDamage'),
                Mf::fromArray($row['mf']),
                0,
            );
        }

        return $items;
    }

    private function intFromShop(string $key): int
    {
        $shop = $this->config->shop();

        return $this->intField($shop, $key);
    }

    private function stringFromShop(string $key): string
    {
        $shop = $this->config->shop();

        return $this->stringField($shop, $key);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        if (! array_key_exists($key, $row) || ! is_int($row[$key])) {
            throw new RuntimeException("Expected int {$key}.");
        }

        return $row[$key];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stringField(array $row, string $key): string
    {
        if (! array_key_exists($key, $row) || ! is_string($row[$key])) {
            throw new RuntimeException("Expected string {$key}.");
        }

        return $row[$key];
    }
}
