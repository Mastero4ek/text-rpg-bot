<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Bag\BagKindEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use Illuminate\Database\Seeder;
use RuntimeException;

final class CharacterSeeder extends Seeder
{
    public function run(): void
    {
        $characters = app(CharacterService::class);
        $backpack = app(BackpackService::class);
        $bag = app(BagService::class);
        $bagCatalog = app(BagCatalog::class);
        $loadout = app(LoadoutService::class);

        foreach ($this->rows() as $row) {
            $character = Character::query()->find($row['tg_id']);

            if (! $character instanceof Character) {
                $character = $characters->createDraft($row['tg_id']);
            }

            $character->username = $row['username'];
            $character->location = $row['location'];
            $character->onboarding_step = OnboardingStepEnum::DONE;
            $character->level = $row['level'];
            $character->exp = $row['exp'];
            $character->silver = $row['silver'];
            $character->gold = $row['gold'];
            $character->save();

            $this->resetOwnership($character);
            $this->fillBackpack($backpack, $loadout, $character, $row['backpack'], $row['equip']);
            $this->fillBagPotions($bag, $character, $row['potions']);
            $this->fillBagGems($bagCatalog, $character, $row['gems']);
        }
    }

    private function resetOwnership(Character $character): void
    {
        LoadoutSlot::query()->where('tg_id', $character->tg_id)->delete();
        BagItem::query()->where('tg_id', $character->tg_id)->delete();
        BackpackItem::query()->where('tg_id', $character->tg_id)->delete();
    }

    /**
     * @param  list<string>  $itemIds
     * @param  list<string>  $equipItemIds
     */
    private function fillBackpack(
        BackpackService $backpack,
        LoadoutService $loadout,
        Character $character,
        array $itemIds,
        array $equipItemIds,
    ): void {
        foreach ($itemIds as $catalogId) {
            $backpack->addItem($character->tg_id, $catalogId);
        }

        foreach ($equipItemIds as $catalogId) {
            $result = $loadout->equipByItemId($character, $catalogId);

            if (! $result->ok) {
                throw new RuntimeException(
                    'Failed to equip ' . $catalogId . ' for tg_id ' . $character->tg_id . ': ' . (string) $result->error,
                );
            }

            if ($result->character instanceof Character) {
                $character = $result->character;
            }
        }
    }

    /**
     * @param  list<string>  $potionIds
     */
    private function fillBagPotions(BagService $bag, Character $character, array $potionIds): void
    {
        foreach ($potionIds as $catalogId) {
            $bag->addPotion($character->tg_id, $catalogId);
        }
    }

    /**
     * @param  list<array{catalog_id: string, durability: int}>  $gems
     */
    private function fillBagGems(BagCatalog $bagCatalog, Character $character, array $gems): void
    {
        foreach ($gems as $entry) {
            if (! $bagCatalog->hasGem($entry['catalog_id'])) {
                throw new RuntimeException('Unknown gem for seed: ' . $entry['catalog_id']);
            }

            $row = new BagItem;
            $row->tg_id = $character->tg_id;
            $row->kind = BagKindEnum::GEM;
            $row->catalog_id = $entry['catalog_id'];
            $row->quantity = 1;
            $row->durability = $entry['durability'];
            $row->backpack_item_id = null;
            $row->created_at = now();
            $row->save();
        }
    }

    /**
     * @return list<array{
     *     tg_id: int,
     *     username: string,
     *     location: string,
     *     level: int,
     *     exp: int,
     *     silver: int,
     *     gold: int,
     *     backpack: list<string>,
     *     equip: list<string>,
     *     potions: list<string>,
     *     gems: list<array{catalog_id: string, durability: int}>
     * }>
     */
    private function rows(): array
    {
        return [
            [
                'tg_id' => 900001,
                'username' => 'Серый Странник',
                'location' => __('onboarding.cities.morion'),
                'level' => 0,
                'exp' => 0,
                'silver' => 40,
                'gold' => 0,
                'backpack' => [
                    'knuckles_0',
                    'heavy_0',
                    'knife_0',
                ],
                'equip' => [
                    'knuckles_0',
                    'heavy_0',
                ],
                'potions' => [
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'stamina_0',
                ],
                'gems' => [
                    ['catalog_id' => 'ruby_0', 'durability' => 10],
                    ['catalog_id' => 'emerald_0', 'durability' => 7],
                ],
            ],
            [
                'tg_id' => 900002,
                'username' => 'Алая Искра',
                'location' => __('onboarding.cities.aurora'),
                'level' => 1,
                'exp' => 120,
                'silver' => 85,
                'gold' => 1,
                'backpack' => [
                    'sword_0',
                    'mobile_0',
                    'mobile_3',
                    'focus_0',
                ],
                'equip' => [
                    'sword_0',
                    'mobile_0',
                    'focus_0',
                ],
                'potions' => [
                    'heal_0',
                    'stamina_0',
                    'stamina_0',
                    'stamina_0',
                    'stamina_0',
                ],
                'gems' => [
                    ['catalog_id' => 'ruby_0', 'durability' => 10],
                    ['catalog_id' => 'sapphire_0', 'durability' => 9],
                    ['catalog_id' => 'emerald_0', 'durability' => 4],
                ],
            ],
            [
                'tg_id' => 900003,
                'username' => 'Каменный Щит',
                'location' => __('onboarding.cities.balance'),
                'level' => 2,
                'exp' => 340,
                'silver' => 150,
                'gold' => 2,
                'backpack' => [
                    'hammer_0',
                    'heavy_1',
                    'mobile_1',
                    'mobile_2',
                    'vital_0',
                    'axe_0',
                ],
                'equip' => [
                    'hammer_0',
                    'heavy_1',
                    'mobile_1',
                    'vital_0',
                ],
                'potions' => [
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'heal_0',
                ],
                'gems' => [
                    ['catalog_id' => 'diamond_0', 'durability' => 10],
                    ['catalog_id' => 'ruby_0', 'durability' => 8],
                    ['catalog_id' => 'sapphire_0', 'durability' => 10],
                    ['catalog_id' => 'emerald_0', 'durability' => 6],
                ],
            ],
        ];
    }
}
