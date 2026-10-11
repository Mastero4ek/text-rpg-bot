<?php

declare(strict_types=1);

namespace App\Actions\Healer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealerTakeRatTailAction
{
    public function __construct(
        private readonly BagService $bag,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for HealerTakeRatTail.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->healer_debt_open
                || ! $locked->bruiser_contract_active
                || ! $locked->healer_rat_loot_pending
            ) {
                return ActionResult::fail(__('telegram.quest.healer.error.debt_wrong_phase'));
            }

            $tailId = $this->ratTailCatalogId();

            if (! $this->bag->canAcceptQuestItem($locked, $tailId)) {
                return ActionResult::fail(__('telegram.quest.healer.error.bag_full'));
            }

            $this->bag->addQuestItem($locked->tg_id, $tailId);
            $this->bag->removeQuestItem($locked->tg_id, $tailId);

            $locked = $this->characters->findByTgId($locked->tg_id);
            $locked->healer_debt_open = false;
            $locked->healer_rat_loot_pending = false;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    private function ratTailCatalogId(): string
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('HealerQuest', $quest['rewards']) || ! is_array($quest['rewards']['HealerQuest'])) {
            throw new RuntimeException('quest.rewards.HealerQuest missing.');
        }

        $healer = $quest['rewards']['HealerQuest'];

        if (! array_key_exists('debt', $healer) || ! is_array($healer['debt'])) {
            throw new RuntimeException('HealerQuest.debt missing.');
        }

        $row = $healer['debt'];

        if (! array_key_exists('rat_tail_catalog_id', $row) || ! is_string($row['rat_tail_catalog_id']) || $row['rat_tail_catalog_id'] === '') {
            throw new RuntimeException('HealerQuest.debt.rat_tail_catalog_id missing.');
        }

        return $row['rat_tail_catalog_id'];
    }
}
