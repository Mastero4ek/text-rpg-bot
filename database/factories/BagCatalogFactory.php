<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Models\Bag\BagCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BagCatalog>
 */
final class BagCatalogFactory extends Factory
{
    protected $model = BagCatalog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $catalogId = 'test_' . fake()->unique()->bothify('gem_##??');

        return [
            'catalog_id' => $catalogId,
            'kind' => BagKindEnum::GEM,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'in_shop' => true,
            'enabled' => true,
            'price' => 25,
            'currency' => CurrencyEnum::SILVER,
            'profile' => null,
            'effect_value' => null,
            'type' => GemTypeEnum::RUBY,
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

    public function potion(): static
    {
        return $this->state(fn (): array => [
            'catalog_id' => 'test_' . fake()->unique()->bothify('potion_##??'),
            'kind' => BagKindEnum::POTION,
            'profile' => ProfileEnum::HEAL,
            'effect_value' => 40,
            'type' => null,
            'max_durability' => null,
            'mf_crit' => 0,
            'in_shop' => true,
        ]);
    }

    public function staminaPotion(): static
    {
        return $this->potion()->state(fn (): array => [
            'profile' => ProfileEnum::STAMINA,
            'effect_value' => 25,
        ]);
    }
}
