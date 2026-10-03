<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\EquipmentProfileEnum;
use App\Enums\Equipment\RepairTierEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
final class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $itemId = 'test_' . fake()->unique()->bothify('item_##??');

        return [
            'item_id' => $itemId,
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'item_type' => TypeEnum::WEAPON,
            'slot' => SlotEnum::RIGHT_HAND,
            'profile' => EquipmentProfileEnum::LIGHT,
            'tier' => 1,
            'in_shop' => true,
            'enabled' => true,
            'price' => 50,
            'currency' => CurrencyEnum::SILVER,
            'vip_only' => false,
            'repair_tier' => RepairTierEnum::NORMAL,
            'weapon_damage' => 5,
            'stat_bonus' => 0,
            'armor' => 0,
            'mf_dodge' => 0,
            'mf_anti_dodge' => 0,
            'mf_crit' => 0,
            'mf_anti_crit' => 0,
            'effect_type' => null,
            'effect_value' => null,
            'sort_order' => 0,
            'repairable' => true,
        ];
    }

    public function potion(): static
    {
        return $this->state(fn (): array => [
            'item_type' => TypeEnum::POTION,
            'slot' => SlotEnum::POCKET,
            'profile' => EquipmentProfileEnum::HEAL,
            'tier' => null,
            'weapon_damage' => 0,
            'repairable' => false,
        ]);
    }

    public function armor(): static
    {
        return $this->state(fn (): array => [
            'item_type' => TypeEnum::ARMOR,
            'slot' => SlotEnum::ARMOR,
            'profile' => EquipmentProfileEnum::HEAVY,
            'weapon_damage' => 0,
            'stat_bonus' => 10,
        ]);
    }
}
