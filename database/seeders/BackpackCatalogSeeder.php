<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Services\Shop\ShopCatalog;
use Illuminate\Database\Seeder;

final class BackpackCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(ShopCatalog::class)->forgetCache();

        foreach ($this->catalog() as $row) {
            $catalog = BackpackCatalog::query()->updateOrCreate(
                ['catalog_id' => $row['catalog_id']],
                $row,
            );

            $catalog->clearMediaCollection('image');
        }
    }

    /**
     * Initial catalog snapshot for fresh installs / tests. Runtime SoT = `backpack_catalog` table.
     *
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        $rows = [
            [
                'catalog_id' => 'knuckles_0',
                'name' => 'Учебный кастет',
                'description' => 'Класс «кастет»: уворот и крит. Базовый экземпляр на 0 уровне.',
                'item_type' => TypeEnum::WEAPON,
                'slot' => SlotEnum::RIGHT_HAND,
                'profile' => ProfileEnum::KNUCKLES,
                'in_shop' => false,
                'enabled' => true,
                'price' => 0,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 1,
                'weapon_damage_max' => 2,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 2,
                'mf_anti_dodge' => 0,
                'mf_crit' => 2,
                'mf_anti_crit' => 0,
                'sort_order' => 0,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'knife_0',
                'name' => 'Учебный нож',
                'description' => 'Лёгкий учебный клинок. Чуть повышает уворот и шанс крита.',
                'item_type' => TypeEnum::WEAPON,
                'slot' => SlotEnum::RIGHT_HAND,
                'profile' => ProfileEnum::KNIFE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 20,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 2,
                'weapon_damage_max' => 3,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 6,
                'mf_anti_dodge' => 0,
                'mf_crit' => 2,
                'mf_anti_crit' => 0,
                'sort_order' => 1,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'axe_0',
                'name' => 'Учебный топор',
                'description' => 'Тяжёлый учебный топор. Бьёт сильнее и чаще критикует.',
                'item_type' => TypeEnum::WEAPON,
                'slot' => SlotEnum::RIGHT_HAND,
                'profile' => ProfileEnum::AXE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 20,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 3,
                'weapon_damage_max' => 4,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 2,
                'mf_crit' => 6,
                'mf_anti_crit' => 0,
                'sort_order' => 2,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'club_0',
                'name' => 'Учебная дубина',
                'description' => 'Простая дубина тренера. Держит антиуворот и антикрит.',
                'item_type' => TypeEnum::WEAPON,
                'slot' => SlotEnum::RIGHT_HAND,
                'profile' => ProfileEnum::CLUB,
                'in_shop' => true,
                'enabled' => true,
                'price' => 20,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 3,
                'weapon_damage_max' => 4,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 4,
                'mf_crit' => 0,
                'mf_anti_crit' => 4,
                'sort_order' => 3,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'sword_0',
                'name' => 'Учебный меч',
                'description' => 'Учебный меч. Баланс уворота и антиуворота.',
                'item_type' => TypeEnum::WEAPON,
                'slot' => SlotEnum::RIGHT_HAND,
                'profile' => ProfileEnum::SWORD,
                'in_shop' => true,
                'enabled' => true,
                'price' => 40,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 4,
                'weapon_damage_max' => 5,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 6,
                'mf_anti_dodge' => 6,
                'mf_crit' => 2,
                'mf_anti_crit' => 2,
                'sort_order' => 4,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'hammer_0',
                'name' => 'Учебный молот',
                'description' => 'Учебный молот. Крит и антиуворот.',
                'item_type' => TypeEnum::WEAPON,
                'slot' => SlotEnum::RIGHT_HAND,
                'profile' => ProfileEnum::HAMMER,
                'in_shop' => true,
                'enabled' => true,
                'price' => 40,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 5,
                'weapon_damage_max' => 6,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 8,
                'mf_crit' => 8,
                'mf_anti_crit' => 2,
                'sort_order' => 5,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'heavy_0',
                'name' => 'Учебная кольчуга',
                'description' => 'Кольчуга новобранца. Запас HP и базовая броня груди.',
                'item_type' => TypeEnum::ARMOR,
                'slot' => SlotEnum::ARMOR,
                'profile' => ProfileEnum::HEAVY,
                'in_shop' => false,
                'enabled' => true,
                'price' => 0,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 15,
                'armor' => 2,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
                'sort_order' => 6,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'heavy_1',
                'name' => 'Учебный баклер',
                'description' => 'Лёгкий щит. Второй блок в раунде и антикрит.',
                'item_type' => TypeEnum::ARMOR,
                'slot' => SlotEnum::SHIELD,
                'profile' => ProfileEnum::HEAVY,
                'in_shop' => true,
                'enabled' => true,
                'price' => 35,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 2,
                'mf_crit' => 0,
                'mf_anti_crit' => 8,
                'sort_order' => 7,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'mobile_0',
                'name' => 'Учебный шлем',
                'description' => 'Лёгкий шлем новичка. Чуть повышает запас HP и уворот.',
                'item_type' => TypeEnum::ARMOR,
                'slot' => SlotEnum::HELMET,
                'profile' => ProfileEnum::MOBILE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 5,
                'armor' => 1,
                'mf_dodge' => 2,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
                'sort_order' => 8,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'mobile_1',
                'name' => 'Учебные сапоги',
                'description' => 'Лёгкие сапоги новичка. Чуть повышают запас HP и антиуворот.',
                'item_type' => TypeEnum::ARMOR,
                'slot' => SlotEnum::BOOTS,
                'profile' => ProfileEnum::MOBILE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 5,
                'armor' => 1,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 2,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
                'sort_order' => 9,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'mobile_2',
                'name' => 'Учебные штаны',
                'description' => 'Простые штаны новичка. Броня пояса и чуть HP.',
                'item_type' => TypeEnum::ARMOR,
                'slot' => SlotEnum::PANTS,
                'profile' => ProfileEnum::MOBILE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 4,
                'armor' => 1,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 1,
                'sort_order' => 10,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'mobile_3',
                'name' => 'Учебные перчатки',
                'description' => 'Лёгкие перчатки новичка. Чуть антикрита и HP.',
                'item_type' => TypeEnum::ARMOR,
                'slot' => SlotEnum::GLOVES,
                'profile' => ProfileEnum::MOBILE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 20,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 3,
                'armor' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 2,
                'sort_order' => 11,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'focus_0',
                'name' => 'Кольцо ученика',
                'description' => 'Простое кольцо ученика. Чуть уворота.',
                'item_type' => TypeEnum::JEWELRY,
                'slot' => SlotEnum::RING_1,
                'profile' => ProfileEnum::FOCUS,
                'in_shop' => true,
                'enabled' => true,
                'price' => 20,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 0,
                'armor' => 0,
                'mf_dodge' => 3,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
                'sort_order' => 12,
                'repairable' => true,
            ],
            [
                'catalog_id' => 'vital_0',
                'name' => 'Амулет ученика',
                'description' => 'Амулет ученика. Запас HP.',
                'item_type' => TypeEnum::JEWELRY,
                'slot' => SlotEnum::AMULET,
                'profile' => ProfileEnum::VITAL,
                'in_shop' => true,
                'enabled' => true,
                'price' => 30,
                'currency' => CurrencyEnum::SILVER,
                'repair_tier' => RepairEnum::NORMAL,
                'weapon_damage_min' => 0,
                'weapon_damage_max' => 0,
                'stat_bonus' => 10,
                'armor' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
                'sort_order' => 13,
                'repairable' => true,
            ],
        ];

        foreach ($rows as $idx => $row) {
            foreach ($this->reqsForItem($row['catalog_id']) as $key => $value) {
                $rows[$idx][$key] = $value;
            }
        }

        for ($i = 0; $i < count($rows); $i++) {
            $rows[$i]['sort_order'] = $i;

            if ($rows[$i]['item_type'] === TypeEnum::JEWELRY) {
                continue;
            }

            if ($rows[$i]['repairable'] !== true) {
                continue;
            }

            if (! array_key_exists('max_durability', $rows[$i])) {
                $rows[$i]['max_durability'] = 40;
                $rows[$i]['durability_loss_per_fight'] = 1;
            }

            if (! array_key_exists('gem_slots', $rows[$i])) {
                $rows[$i]['gem_slots'] = 1;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function reqsForItem(string $catalogId): array
    {
        return match ($catalogId) {
            'sword_0' => ['req_level' => 1, 'req_strength' => 1, 'req_agility' => 1],
            'hammer_0' => ['req_level' => 1, 'req_strength' => 2, 'req_instinct' => 1],
            'heavy_1' => ['req_vitality' => 1],
            'mobile_0', 'mobile_1', 'mobile_2', 'mobile_3' => ['req_vitality' => 1],
            'vital_0' => ['req_vitality' => 1],
            default => [],
        };
    }
}
