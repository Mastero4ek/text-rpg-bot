<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackpackCatalog>
 */
final class BackpackCatalogFactory extends Factory
{
    protected $model = BackpackCatalog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $catalogId = 'test_' . fake()->unique()->bothify('item_##??');

        return [
            'catalog_id' => $catalogId,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'item_type' => TypeEnum::WEAPON,
            'slot' => SlotEnum::RIGHT_HAND,
            'profile' => ProfileEnum::KNIFE,
            'enabled' => true,
            'price' => 50,
            'currency' => CurrencyEnum::SILVER,
            'repair_tier' => RepairEnum::NORMAL,
            'weapon_damage_min' => 5,
            'weapon_damage_max' => 6,
            'stat_bonus' => 0,
            'armor' => 0,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 0,
            'mf_crit' => 0,
            'mf_anti_crit' => 0,
            'sort_order' => 0,
            'repairable' => true,
        ];
    }

    public function armor(): static
    {
        return $this->state(fn (): array => [
            'item_type' => TypeEnum::ARMOR,
            'slot' => SlotEnum::ARMOR,
            'profile' => ProfileEnum::HEAVY,
            'weapon_damage_min' => 0,
            'weapon_damage_max' => 0,
            'stat_bonus' => 10,
        ]);
    }
}
