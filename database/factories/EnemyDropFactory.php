<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enemy\EnemyCatalog;
use App\Models\Enemy\EnemyDrop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnemyDrop>
 */
final class EnemyDropFactory extends Factory
{
    protected $model = EnemyDrop::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enemy_catalog_id' => EnemyCatalog::factory(),
            'bag_catalog_id' => 'heal_0',
            'chance_pct' => 50,
        ];
    }
}
