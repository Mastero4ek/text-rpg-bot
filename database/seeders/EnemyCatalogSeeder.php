<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Enemy\EnemyKindEnum;
use App\Models\Enemy\EnemyCatalog;
use App\Models\Enemy\EnemyDrop;
use Illuminate\Database\Seeder;

final class EnemyCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            $drops = $row['drops'];
            unset($row['drops']);

            EnemyCatalog::query()->updateOrCreate(
                ['catalog_id' => $row['catalog_id']],
                $row,
            );

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
                'in_fight_menu' => false,
                'level' => 0,
                'strength' => 2,
                'agility' => 2,
                'instinct' => 2,
                'vitality' => 3,
                'max_hp' => 70,
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
                'catalog_id' => 'chance_wanderer',
                'kind' => EnemyKindEnum::MIRROR,
                'name' => 'Случайный бродяга',
                'description' => null,
                'enabled' => true,
                'in_fight_menu' => true,
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
        ];
    }
}
