<?php

declare(strict_types=1);

namespace App\Actions\Healer;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Bag\BagItem;
use App\Models\Character\Character;
use App\Quests\Chapter1\Chapter1ScreenProjector;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealerGivePotionAction
{
    public function __construct(
        private readonly BagService $bag,
        private readonly CharacterService $characters,
        private readonly Chapter1ScreenProjector $projector,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for HealerGivePotion.');
            }

            if ($locked->healer_at_tavern || $locked->quest_phase !== QuestPhaseEnum::Healer) {
                return ActionResult::fail(__('telegram.quest.healer.error.already_done'));
            }

            if (! $locked->unlock_portal) {
                return ActionResult::fail(__('telegram.quest.healer.error.wrong_phase'));
            }

            $reward = $this->reward();
            $catalogIds = $reward['potion_catalog_ids'];
            $newRows = 0;

            foreach ($catalogIds as $catalogId) {
                $hasStackSpace = BagItem::query()
                    ->where('tg_id', $locked->tg_id)
                    ->where('kind', BagKindEnum::POTION->value)
                    ->whereNull('backpack_item_id')
                    ->where('catalog_id', $catalogId)
                    ->where('quantity', '<', $this->bag->potionMaxStack())
                    ->exists();

                if (! $hasStackSpace) {
                    $newRows++;
                }
            }

            if ($newRows > 0 && ! $this->bag->canAcceptBagRows($locked, $newRows)) {
                return ActionResult::fail(__('telegram.quest.healer.error.bag_full'));
            }

            foreach ($catalogIds as $catalogId) {
                $this->bag->addPotion($locked->tg_id, $catalogId);
            }

            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            $this->projector->writeAfterHealerComplete($locked);
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int, potion_catalog_ids: list<string>}
     */
    private function reward(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('HealerQuest', $quest['rewards']) || ! is_array($quest['rewards']['HealerQuest'])) {
            throw new RuntimeException('quest.rewards.HealerQuest missing.');
        }

        $row = $quest['rewards']['HealerQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('HealerQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('HealerQuest.silver missing.');
        }

        if (! array_key_exists('potion_catalog_ids', $row) || ! is_array($row['potion_catalog_ids']) || $row['potion_catalog_ids'] === []) {
            throw new RuntimeException('HealerQuest.potion_catalog_ids missing.');
        }

        $catalogIds = [];

        foreach ($row['potion_catalog_ids'] as $catalogId) {
            if (! is_string($catalogId) || $catalogId === '') {
                throw new RuntimeException('HealerQuest.potion_catalog_ids entry invalid.');
            }

            $catalogIds[] = $catalogId;
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'potion_catalog_ids' => $catalogIds,
        ];
    }
}
