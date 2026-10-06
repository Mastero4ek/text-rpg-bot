<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Enemy\EnemyKindEnum;
use App\Models\Enemy\EnemyCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnemyCatalog>
 */
final class EnemyCatalogFactory extends Factory
{
    protected $model = EnemyCatalog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_id' => 'test_fixed_' . fake()->unique()->numerify('##??'),
            'kind' => EnemyKindEnum::FIXED,
            'name' => fake()->words(2, true),
            'description' => null,
            'enabled' => true,
            'level' => 1,
            'strength' => 5,
            'agility' => 5,
            'instinct' => 5,
            'vitality' => 5,
            'max_hp' => 80,
            'weapon_damage' => 2,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 0,
            'mf_crit' => 0,
            'mf_anti_crit' => 0,
            'power_pct' => null,
            'reward_exp_pct' => 100,
            'reward_silver_min' => 10,
            'reward_silver_max' => 20,
            'sort_order' => 0,
        ];
    }

    public function mirror(): static
    {
        return $this->state(fn (): array => [
            'catalog_id' => 'test_mirror_' . fake()->unique()->numerify('##??'),
            'kind' => EnemyKindEnum::MIRROR,
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
        ]);
    }
}
