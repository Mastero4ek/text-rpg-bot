<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Backpack\BackpackCatalog;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use Illuminate\Database\Seeder;

final class CitySeeder extends Seeder
{
    public const int PORTAL_COST_SILVER = 10;

    public function run(): void
    {
        foreach ($this->cities() as $row) {
            City::query()->updateOrCreate(
                ['key' => $row['key']],
                $row,
            );
        }

        $this->attachShopCatalog();
        $this->attachForestCatalog();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cities(): array
    {
        return [
            [
                'key' => City::KEY_YASEN,
                'name' => 'Ясень',
                'enabled' => true,
                'portal_cost_silver' => self::PORTAL_COST_SILVER,
                'has_blacksmith' => true,
                'has_healer' => true,
                'has_buyer' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => true,
                'has_fights_list' => true,
                'has_training_room' => true,
            ],
            [
                'key' => City::KEY_KURGAN,
                'name' => 'Курган',
                'enabled' => true,
                'portal_cost_silver' => self::PORTAL_COST_SILVER,
                'has_blacksmith' => true,
                'has_healer' => false,
                'has_buyer' => false,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => false,
                'has_fights_list' => true,
                'has_training_room' => true,
            ],
            [
                'key' => City::KEY_LIMAN,
                'name' => 'Лиман',
                'enabled' => true,
                'portal_cost_silver' => self::PORTAL_COST_SILVER,
                'has_blacksmith' => true,
                'has_healer' => true,
                'has_buyer' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => true,
                'has_fights_list' => true,
                'has_training_room' => true,
            ],
        ];
    }

    private function attachForestCatalog(): void
    {
        $forestIds = [];

        foreach (City::query()->whereIn('key', [City::KEY_YASEN, City::KEY_LIMAN])->get() as $city) {
            $forestIds[] = $city->id;
        }

        foreach (EnemyCatalog::query()->where('catalog_id', '!=', EnemyCatalog::TUTORIAL_CATALOG_ID)->get() as $catalog) {
            $catalog->cities()->sync($forestIds);
        }
    }

    private function attachShopCatalog(): void
    {
        $shopIds = [];

        foreach (City::query()->whereIn('key', [City::KEY_YASEN, City::KEY_LIMAN])->get() as $city) {
            $shopIds[] = $city->id;
        }

        foreach (BackpackCatalog::query()->whereNotIn('catalog_id', ['knuckles_0', 'heavy_0'])->get() as $catalog) {
            $catalog->cities()->sync($shopIds);
        }

        foreach (BagCatalog::query()->whereNotIn('catalog_id', ['diamond_0'])->get() as $catalog) {
            $catalog->cities()->sync($shopIds);
        }
    }
}
