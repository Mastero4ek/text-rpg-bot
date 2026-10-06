<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Models\Bag\BagCatalog;
use App\Services\Bag\BagCatalog as BagCatalogService;
use Illuminate\Database\Seeder;

final class BagCatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(BagCatalogService::class)->forgetCache();

        $sort = 0;

        foreach ($this->catalog() as $row) {
            $row['sort_order'] = $sort;
            $sort++;

            BagCatalog::query()->updateOrCreate(
                ['catalog_id' => $row['catalog_id']],
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
                'catalog_id' => 'ruby_0',
                'kind' => BagKindEnum::GEM,
                'name' => 'Рубин ученика',
                'description' => null,
                'type' => GemTypeEnum::RUBY,
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
                'catalog_id' => 'emerald_0',
                'kind' => BagKindEnum::GEM,
                'name' => 'Изумруд ученика',
                'description' => null,
                'type' => GemTypeEnum::EMERALD,
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
                'catalog_id' => 'sapphire_0',
                'kind' => BagKindEnum::GEM,
                'name' => 'Сапфир ученика',
                'description' => null,
                'type' => GemTypeEnum::SAPPHIRE,
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
                'catalog_id' => 'diamond_0',
                'kind' => BagKindEnum::GEM,
                'name' => 'Алмаз ученика',
                'description' => null,
                'type' => GemTypeEnum::DIAMOND,
                'enabled' => true,
                'price' => 25,
                'currency' => CurrencyEnum::SILVER,
                'max_durability' => 10,
                'mf_dodge' => 0,
                'mf_anti_dodge' => 4,
                'mf_crit' => 0,
                'mf_anti_crit' => 0,
            ],
            [
                'catalog_id' => 'heal_0',
                'kind' => BagKindEnum::POTION,
                'name' => 'Зелье лечения',
                'description' => 'Флакон красного зелья. Восстанавливает HP в бою.',
                'profile' => ProfileEnum::HEAL,
                'effect_value' => 40,
                'enabled' => true,
                'price' => 15,
                'currency' => CurrencyEnum::SILVER,
            ],
            [
                'catalog_id' => 'stamina_0',
                'kind' => BagKindEnum::POTION,
                'name' => 'Зелье выносливости',
                'description' => 'Флакон зелёного зелья. Восстанавливает выносливость в бою.',
                'profile' => ProfileEnum::STAMINA,
                'effect_value' => 25,
                'enabled' => true,
                'price' => 12,
                'currency' => CurrencyEnum::SILVER,
            ],
        ];
    }
}
