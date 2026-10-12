<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Enemy\EnemyKindEnum;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Enemy\EnemyDrop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class EnemyCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            $drops = $row['drops'];
            unset($row['drops']);

            $catalog = EnemyCatalog::query()->updateOrCreate(
                ['catalog_id' => $row['catalog_id']],
                $row,
            );

            $this->attachImage($catalog);

            EnemyDrop::query()
                ->where('enemy_catalog_id', $row['catalog_id'])
                ->delete();

            foreach ($drops as $drop) {
                $rowDrop = new EnemyDrop;
                $rowDrop->enemy_catalog_id = $row['catalog_id'];
                $rowDrop->bag_catalog_id = $drop['bag_catalog_id'];
                $rowDrop->chance_pct = $drop['chance_pct'];
                $rowDrop->save();
            }
        }
    }

    private function attachImage(EnemyCatalog $catalog): void
    {
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
            $path = resource_path('images/telegram/enemy/' . $catalog->catalog_id . '.' . $ext);

            if (! is_file($path)) {
                continue;
            }

            $catalog->clearMediaCollection('image');
            $catalog->addMedia($path)
                ->preservingOriginal()
                ->withProperties(['uuid' => (string) Str::uuid()])
                ->toMediaCollection('image');

            return;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        return [
            [
                'catalog_id' => EnemyCatalog::TUTORIAL_CATALOG_ID,
                'kind' => EnemyKindEnum::FIXED,
                'name' => 'Деревянный солдат',
                'description' => null,
                'enabled' => true,
                'level' => 0,
                'strength' => 2,
                'agility' => 2,
                'instinct' => 2,
                'vitality' => 3,
                'max_hp' => 60,
                'weapon_damage' => 0,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
                'power_pct' => null,
                'reward_exp_pct' => 0,
                'reward_silver_min' => 0,
                'reward_silver_max' => 0,
                'sort_order' => 0,
                'drops' => [],
            ],
            [
                'catalog_id' => 'forest_bandit',
                'kind' => EnemyKindEnum::MIRROR,
                'name' => 'Лесной разбойник',
                'description' => null,
                'enabled' => true,
                'level' => null,
                'strength' => null,
                'agility' => null,
                'instinct' => null,
                'vitality' => null,
                'max_hp' => null,
                'weapon_damage' => null,
                'mf_dodge' => null,
                'mf_anti_dodge' => null,
                'mf_crit' => null,
                'mf_anti_crit' => null,
                'power_pct' => 100,
                'reward_exp_pct' => 30,
                'reward_silver_min' => 10,
                'reward_silver_max' => 20,
                'sort_order' => 1,
                'drops' => [
                    [
                        'bag_catalog_id' => 'heal_0',
                        'chance_pct' => 7,
                    ],
                ],
            ],
            [
                'catalog_id' => 'rabid_rat',
                'kind' => EnemyKindEnum::MIRROR,
                'name' => 'Бешеная крыса',
                'description' => null,
                'enabled' => true,
                'level' => null,
                'strength' => null,
                'agility' => null,
                'instinct' => null,
                'vitality' => null,
                'max_hp' => null,
                'weapon_damage' => null,
                'mf_dodge' => null,
                'mf_anti_dodge' => null,
                'mf_crit' => null,
                'mf_anti_crit' => null,
                'power_pct' => 75,
                'reward_exp_pct' => 20,
                'reward_silver_min' => 6,
                'reward_silver_max' => 14,
                'sort_order' => 2,
                'drops' => [
                    [
                        'bag_catalog_id' => 'rat_tail',
                        'chance_pct' => 100,
                    ],
                ],
            ],
            [
                'catalog_id' => 'wild_dog',
                'kind' => EnemyKindEnum::MIRROR,
                'name' => 'Дикая собака',
                'description' => null,
                'enabled' => true,
                'level' => null,
                'strength' => null,
                'agility' => null,
                'instinct' => null,
                'vitality' => null,
                'max_hp' => null,
                'weapon_damage' => null,
                'mf_dodge' => null,
                'mf_anti_dodge' => null,
                'mf_crit' => null,
                'mf_anti_crit' => null,
                'power_pct' => 85,
                'reward_exp_pct' => 25,
                'reward_silver_min' => 8,
                'reward_silver_max' => 16,
                'sort_order' => 3,
                'drops' => [],
            ],
            [
                'catalog_id' => 'swamp_witch',
                'kind' => EnemyKindEnum::MIRROR,
                'name' => 'Болотная ведьма',
                'description' => null,
                'enabled' => true,
                'level' => null,
                'strength' => null,
                'agility' => null,
                'instinct' => null,
                'vitality' => null,
                'max_hp' => null,
                'weapon_damage' => null,
                'mf_dodge' => null,
                'mf_anti_dodge' => null,
                'mf_crit' => null,
                'mf_anti_crit' => null,
                'power_pct' => 115,
                'reward_exp_pct' => 35,
                'reward_silver_min' => 14,
                'reward_silver_max' => 26,
                'sort_order' => 4,
                'drops' => [
                    [
                        'bag_catalog_id' => 'stamina_0',
                        'chance_pct' => 10,
                    ],
                ],
            ],
        ];
    }
}
