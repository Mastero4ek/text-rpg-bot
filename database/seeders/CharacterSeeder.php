<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Gem;
use App\Models\Inventory;
use App\Models\LoadoutSlot;
use App\Services\Character\CharacterService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\LoadoutService;
use Illuminate\Database\Seeder;
use RuntimeException;

final class CharacterSeeder extends Seeder
{
    public function run(): void
    {
        $characters = app(CharacterService::class);
        $inventory = app(InventoryService::class);
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

            $this->fillBackpack($inventory, $loadout, $character, $row['backpack'], $row['equip']);
            $this->fillBag($character, $row['bag']);
        }
    }

    /**
     * @param  list<string>  $itemIds
     * @param  list<string>  $equipItemIds
     */
    private function fillBackpack(
        InventoryService $inventory,
        LoadoutService $loadout,
        Character $character,
        array $itemIds,
        array $equipItemIds,
    ): void {
        LoadoutSlot::query()->where('tg_id', $character->tg_id)->delete();
        Inventory::query()->where('tg_id', $character->tg_id)->delete();

        foreach ($itemIds as $itemId) {
            $inventory->addItem($character->tg_id, $itemId);
        }

        foreach ($equipItemIds as $itemId) {
            $result = $loadout->equipByItemId($character, $itemId);

            if (! $result->ok) {
                throw new RuntimeException(
                    'Failed to equip ' . $itemId . ' for tg_id ' . $character->tg_id . ': ' . (string) $result->error,
                );
            }

            if ($result->character instanceof Character) {
                $character = $result->character;
            }
        }
    }

    /**
     * @param  list<array{gem_id: string, durability: int}>  $bag
     */
    private function fillBag(Character $character, array $bag): void
    {
        $pouch = [];
        $addedAt = now()->toIso8601String();

        foreach ($bag as $entry) {
            if (! Gem::query()->withTrashed()->whereKey($entry['gem_id'])->exists()) {
                throw new RuntimeException('Unknown gem for seed: ' . $entry['gem_id']);
            }

            $pouch[] = [
                'gem_id' => $entry['gem_id'],
                'durability' => $entry['durability'],
                'added_at' => $addedAt,
            ];
        }

        if (count($pouch) > $character->bag_max_rows) {
            $character->bag_max_rows = count($pouch);
        }

        $character->gem_pouch = $pouch;
        $character->save();
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
     *     bag: list<array{gem_id: string, durability: int}>
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
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'stamina_0',
                ],
                'equip' => [
                    'knuckles_0',
                    'heavy_0',
                ],
                'bag' => [
                    ['gem_id' => 'ruby_0', 'durability' => 10],
                    ['gem_id' => 'emerald_0', 'durability' => 7],
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
                    'heal_0',
                    'stamina_0',
                    'stamina_0',
                    'stamina_0',
                    'stamina_0',
                ],
                'equip' => [
                    'sword_0',
                    'mobile_0',
                    'focus_0',
                ],
                'bag' => [
                    ['gem_id' => 'ruby_0', 'durability' => 10],
                    ['gem_id' => 'sapphire_0', 'durability' => 9],
                    ['gem_id' => 'emerald_0', 'durability' => 4],
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
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'heal_0',
                    'axe_0',
                ],
                'equip' => [
                    'hammer_0',
                    'heavy_1',
                    'mobile_1',
                    'vital_0',
                ],
                'bag' => [
                    ['gem_id' => 'diamond_0', 'durability' => 10],
                    ['gem_id' => 'ruby_0', 'durability' => 8],
                    ['gem_id' => 'sapphire_0', 'durability' => 10],
                    ['gem_id' => 'emerald_0', 'durability' => 6],
                ],
            ],
        ];
    }
}
