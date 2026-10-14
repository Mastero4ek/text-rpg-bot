<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Bag\BagKindEnum;
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
        $this->attachPveCatalogs();
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
                'has_bruiser' => true,
                'has_barkeeper' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => true,
                'has_swamp' => true,
                'has_ruins' => true,
                'has_port' => true,
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
                'has_healer' => true,
                'has_buyer' => true,
                'has_overseer' => true,
                'has_bruiser' => true,
                'has_barkeeper' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => true,
                'has_swamp' => true,
                'has_ruins' => true,
                'has_port' => true,
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
                'has_bruiser' => true,
                'has_barkeeper' => true,
                'has_quest_board' => true,
                'has_portal' => true,
                'has_forest' => true,
                'has_swamp' => true,
                'has_ruins' => true,
                'has_port' => true,
                'has_fights_list' => true,
                'has_training_room' => true,
            ],
        ];
    }

    private function attachPveCatalogs(): void
    {
        $forestIds = $this->cityIdsWithFlag('has_forest');
        $swampIds = $this->cityIdsWithFlag('has_swamp');
        $ruinsIds = $this->cityIdsWithFlag('has_ruins');
        $portIds = $this->cityIdsWithFlag('has_port');

        $bandit = EnemyCatalog::query()->find('forest_bandit');
        $witch = EnemyCatalog::query()->find('swamp_witch');
        $dog = EnemyCatalog::query()->find('wild_dog');
        $rat = EnemyCatalog::query()->find('rabid_rat');

        if ($bandit instanceof EnemyCatalog) {
            $bandit->cities()->sync($forestIds);
            $bandit->swampCities()->sync([]);
            $bandit->ruinsCities()->sync([]);
            $bandit->portCities()->sync([]);
        }

        if ($witch instanceof EnemyCatalog) {
            $witch->cities()->sync([]);
            $witch->swampCities()->sync($swampIds);
            $witch->ruinsCities()->sync([]);
            $witch->portCities()->sync([]);
        }

        if ($dog instanceof EnemyCatalog) {
            $dog->cities()->sync([]);
            $dog->swampCities()->sync([]);
            $dog->ruinsCities()->sync($ruinsIds);
            $dog->portCities()->sync([]);
        }

        if ($rat instanceof EnemyCatalog) {
            $rat->cities()->sync([]);
            $rat->swampCities()->sync([]);
            $rat->ruinsCities()->sync([]);
            $rat->portCities()->sync($portIds);
        }
    }

    /**
     * @return list<int>
     */
    private function cityIdsWithFlag(string $flag): array
    {
        $ids = [];

        foreach (City::query()->where($flag, true)->get() as $city) {
            $ids[] = $city->id;
        }

        return $ids;
    }

    private function attachTrainingCatalog(): void
    {
        $trainingIds = [];

        foreach (City::query()->where('has_training_room', true)->get() as $city) {
            $trainingIds[] = $city->id;
        }

        foreach ([EnemyCatalog::TUTORIAL_CATALOG_ID, EnemyCatalog::BRUISER_CATALOG_ID] as $catalogId) {
            $catalog = EnemyCatalog::query()->find($catalogId);

            if (! $catalog instanceof EnemyCatalog) {
                continue;
            }

            $catalog->trainingCities()->sync($trainingIds);
        }
    }

    private function attachShopCatalog(): void
    {
        $shopIds = [];

        foreach (City::query()->whereIn('key', [City::KEY_ANKRAT, City::KEY_THORNBREAK])->get() as $city) {
            $shopIds[] = $city->id;
        }

        foreach (BackpackCatalog::query()->whereNotIn('catalog_id', ['worn_mail', 'bruiser_knuckle'])->get() as $catalog) {
            $catalog->cities()->sync($shopIds);
        }

        foreach (
            BagCatalog::query()
                ->whereNotIn('catalog_id', ['diamond_0', 'buyer_purse', 'rat_tail'])
                ->where('kind', '!=', BagKindEnum::QUEST->value)
                ->get() as $catalog
        ) {
            $catalog->cities()->sync($shopIds);
        }
    }
}
