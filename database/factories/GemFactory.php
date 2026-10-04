<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Models\Gem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gem>
 */
final class GemFactory extends Factory
{
    protected $model = Gem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gemId = 'test_' . fake()->unique()->bothify('gem_##??');

        return [
            'gem_id' => $gemId,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'type' => GemTypeEnum::RUBY,
            'in_shop' => true,
            'enabled' => true,
            'price' => 25,
            'currency' => CurrencyEnum::SILVER,
            'max_durability' => 10,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 0,
            'mf_crit' => 4,
            'mf_anti_crit' => 0,
            'sort_order' => 0,
        ];
    }

    public function emerald(): static
    {
        return $this->state(fn (): array => [
            'type' => GemTypeEnum::EMERALD,
            'mf_dodge' => 4,
            'mf_anti_dodge' => 0,
            'mf_crit' => 0,
            'mf_anti_crit' => 0,
        ]);
    }

    public function sapphire(): static
    {
        return $this->state(fn (): array => [
            'type' => GemTypeEnum::SAPPHIRE,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 0,
            'mf_crit' => 0,
            'mf_anti_crit' => 4,
        ]);
    }

    public function diamond(): static
    {
        return $this->state(fn (): array => [
            'type' => GemTypeEnum::DIAMOND,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 4,
            'mf_crit' => 0,
            'mf_anti_crit' => 0,
            'in_shop' => false,
        ]);
    }
}
