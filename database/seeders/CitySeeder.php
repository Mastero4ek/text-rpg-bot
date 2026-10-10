<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Backpack\BackpackCatalog;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class CitySeeder extends Seeder
{
    public const int PORTAL_COST_SILVER = 10;

    public function run(): void
    {
        foreach ($this->cities() as $row) {
            $city = City::query()->updateOrCreate(
                ['key' => $row['key']],
                $row,
            );

            $this->attachImage($city);
        }

        $this->attachShopCatalog();
        $this->attachForestCatalog();
        $this->attachTrainingCatalog();
    }

    private function attachImage(City $city): void
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $path = resource_path('images/telegram/city/' . $city->key . '.' . $ext);

            if (! is_file($path)) {
                continue;
            }

            $city->clearMediaCollection('image');
            $city->addMedia($path)
                ->preservingOriginal()
                ->withProperties(['uuid' => (string) Str::uuid()])
                ->toMediaCollection('image');

            return;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cities(): array
    {
        return [
            [
                'key' => City::KEY_ANKRAT,
                'name' => 'Анкрат',
                'description' => "<i>Ты стоишь на круговой площади <b>Анкрата</b>. Солнце садится за башней, тени длинные, толпа гудит у лавок.\n\nЭто главный опорный пункт — нерушимая каменная цитадель, где слово орденов весит больше серебра.</i>",
                'enabled' => true,
                'portal_cost_silver' => self::PORTAL_COST_SILVER,
                'has_blacksmith' => true,
                'has_healer' => true,
                'has_buyer' => true,
                'has_overseer' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => true,
                'has_fights_list' => true,
                'has_training_room' => true,
            ],
            [
                'key' => City::KEY_ELDWOOD,
                'name' => 'Эльдвуд',
                'description' => "<i>Ты стоишь на площади <b>Эльдвуда</b>. Солнце тонет в дымке над холмами, шатры дышат тенью, толпа — сплошные силуэты.\n\nЭто приграничный город: дальше — светящийся портал у ворот и путь, откуда возвращаются не все.</i>",
                'enabled' => true,
                'portal_cost_silver' => self::PORTAL_COST_SILVER,
                'has_blacksmith' => true,
                'has_healer' => false,
                'has_buyer' => false,
                'has_overseer' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => false,
                'has_fights_list' => true,
                'has_training_room' => true,
            ],
            [
                'key' => City::KEY_THORNBREAK,
                'name' => 'Торнбрейк',
                'description' => "<i>Ты в <b>Торнбрейк</b>. Имя режет, как сломленный хребет. На круглой площади — толпа в плащах, башня сторожит, в центре — каменная чаша, вокруг которой спорят клинок и монета.\n\nШахты, порт, контрабанда: здесь платят за силу, не за улыбку.</i>",
                'enabled' => true,
                'portal_cost_silver' => self::PORTAL_COST_SILVER,
                'has_blacksmith' => true,
                'has_healer' => true,
                'has_buyer' => true,
                'has_overseer' => true,
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

        foreach (City::query()->whereIn('key', [City::KEY_ANKRAT, City::KEY_THORNBREAK])->get() as $city) {
            $forestIds[] = $city->id;
        }

        foreach (EnemyCatalog::query()->where('catalog_id', '!=', EnemyCatalog::TUTORIAL_CATALOG_ID)->get() as $catalog) {
            $catalog->cities()->sync($forestIds);
        }
    }

    private function attachTrainingCatalog(): void
    {
        $trainingIds = [];

        foreach (City::query()->where('has_training_room', true)->get() as $city) {
            $trainingIds[] = $city->id;
        }

        $soldier = EnemyCatalog::query()->find(EnemyCatalog::TUTORIAL_CATALOG_ID);

        if (! $soldier instanceof EnemyCatalog) {
            return;
        }

        $soldier->trainingCities()->sync($trainingIds);
    }

    private function attachShopCatalog(): void
    {
        $shopIds = [];

        foreach (City::query()->whereIn('key', [City::KEY_ANKRAT, City::KEY_THORNBREAK])->get() as $city) {
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
