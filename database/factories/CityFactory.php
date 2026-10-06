<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
final class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'c_' . fake()->unique()->numerify('######'),
            'name' => fake()->unique()->city(),
            'enabled' => true,
            'characters_max_rows' => City::DEFAULT_CHARACTERS_MAX_ROWS,
            'portal_cost_silver' => 10,
            'has_shop' => true,
            'has_smith' => true,
            'has_hospital' => true,
            'has_portal' => true,
            'has_arena' => true,
            'has_forest' => true,
            'has_training' => true,
        ];
    }
}
