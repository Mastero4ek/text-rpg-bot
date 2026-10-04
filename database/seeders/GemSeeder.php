<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Models\Gem;
use Illuminate\Database\Seeder;

final class GemSeeder extends Seeder
{
    public function run(): void
    {
        $sort = 0;

        foreach ($this->catalog() as $row) {
            $row['sort_order'] = $sort;
            $sort++;

            Gem::query()->updateOrCreate(
                ['gem_id' => $row['gem_id']],
                $row,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalog(): array
    {
        return [
            [
                'gem_id' => 'ruby_0',
                'name' => 'Рубин новичка',
                'description' => null,
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
            ],
            [
                'gem_id' => 'emerald_0',
                'name' => 'Изумруд новичка',
                'description' => null,
                'type' => GemTypeEnum::EMERALD,
                'in_shop' => true,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'max_durability' => 10,
                'mf_dodge' => 4,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
            ],
            [
                'gem_id' => 'sapphire_0',
                'name' => 'Сапфир новичка',
                'description' => null,
                'type' => GemTypeEnum::SAPPHIRE,
                'in_shop' => true,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'max_durability' => 10,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 0,
                'mf_crit' => 0,
                'mf_anti_crit' => 4,
            ],
            [
                'gem_id' => 'diamond_0',
                'name' => 'Алмаз новичка',
                'description' => null,
                'type' => GemTypeEnum::DIAMOND,
                'in_shop' => false,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'max_durability' => 10,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 4,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
            ],
        ];
    }
}
