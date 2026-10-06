<?php

declare(strict_types=1);

namespace App\Actions\Enemy;

use App\Enums\Bag\BagKindEnum;
use App\Models\Bag\BagCatalog;
use App\Models\Character;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\CombatService;
use App\Support\Enemy;
use App\Support\Random\RandomSourceContract;

final class EnemyApplyWinLootAction
{
    public function __construct(
        private readonly CombatService $combat,
        private readonly CharacterService $characters,
        private readonly BagService $bag,
        private readonly RandomSourceContract $random,
    ) {}

    /**
     * @return array{exp: int, silver: int, drop_names: list<string>}
     */
    public function handle(Character $character, Enemy $enemy): array
    {
        $reward = $this->combat->pveRewards($character, $enemy);
        $this->characters->addExpSilver($character, $reward['exp'], $reward['silver']);
        $character = $this->characters->findByTgId($character->tg_id);

        return [
            'exp' => $reward['exp'],
            'silver' => $reward['silver'],
            'drop_names' => $this->grantDrops($character, $enemy),
        ];
    }

    /**
     * @return list<string>
     */
    private function grantDrops(Character $character, Enemy $enemy): array
    {
        $names = [];

        foreach ($enemy->drops as $drop) {
            if ($this->random->float() * 100 >= $drop['chance_pct']) {
                continue;
            }

            $item = BagCatalog::query()->find($drop['bag_catalog_id']);

            if (! $item instanceof BagCatalog) {
                continue;
            }

            if ($item->kind === BagKindEnum::POTION) {
                if (! $this->bag->canAcceptPotion($character, $item->catalog_id)) {
                    continue;
                }

                $this->bag->addPotion($character->tg_id, $item->catalog_id);
                $names[] = $item->name;
                $character = $this->characters->findByTgId($character->tg_id);

                continue;
            }

            if (! $this->bag->canAcceptBagRows($character, 1)) {
                continue;
            }

            $this->bag->grantGem($character, $item->catalog_id, 1);
            $names[] = $item->name;
            $character = $this->characters->findByTgId($character->tg_id);
        }

        return $names;
    }
}
