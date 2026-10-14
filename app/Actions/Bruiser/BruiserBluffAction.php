<?php

declare(strict_types=1);

namespace App\Actions\Bruiser;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Services\Bag\BagService;
use App\Services\Character\CharacterService;
use App\Services\GameConfig;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BruiserBluffAction
{
    public function __construct(
        private readonly BagService $bag,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
        private readonly QuestNpcPlacement $npcPlacement,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for BruiserBluff.');
            }

            if (! $this->npcPlacement->cityHasBruiser($locked)) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.no_bruiser'));
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->bruiser_contract_active
                || $locked->healer_debt_open
            ) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.wrong_phase'));
            }

            $reward = $this->reward();
            $purseId = $reward['purse_catalog_id'];

            if (! $this->bag->canAcceptQuestItem($locked, $purseId)) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.bag_full'));
            }

            if (! $this->bag->ownsQuestItem($locked->tg_id, $purseId)) {
                $this->bag->addQuestItem($locked->tg_id, $purseId);
            }

            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            $locked->bruiser_contract_active = false;
            $locked->bruiser_knuckle_pending = false;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int, purse_catalog_id: string}
     */
    private function reward(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BruiserQuest', $quest['rewards']) || ! is_array($quest['rewards']['BruiserQuest'])) {
            throw new RuntimeException('quest.rewards.BruiserQuest missing.');
        }

        $row = $quest['rewards']['BruiserQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('BruiserQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('BruiserQuest.silver missing.');
        }

        if (! array_key_exists('purse_catalog_id', $row) || ! is_string($row['purse_catalog_id']) || $row['purse_catalog_id'] === '') {
            throw new RuntimeException('BruiserQuest.purse_catalog_id missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'purse_catalog_id' => $row['purse_catalog_id'],
        ];
    }
}
