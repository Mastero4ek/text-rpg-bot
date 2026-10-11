<?php

declare(strict_types=1);

namespace App\Actions\Buyer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BuyerTurnInPurseAction
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
                throw new RuntimeException('Character missing for BuyerTurnInPurse.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->buyer_at_tavern
                || $locked->bruiser_contract_active
            ) {
                return ActionResult::fail(__('telegram.quest.buyer.error.turn_in_phase'));
            }

            $reward = $this->reward();
            $purseId = $reward['purse_catalog_id'];

            if (! $this->bag->ownsQuestItem($locked->tg_id, $purseId)) {
                return ActionResult::fail(__('telegram.quest.buyer.error.no_purse'));
            }

            $this->bag->removeQuestItem($locked->tg_id, $purseId);

            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            if ($reward['gold'] > 0) {
                $locked = $this->characters->grantGold($locked, $reward['gold']);
            }

            $locked->city_unlocked_full = true;
            $locked->quest_phase = QuestPhaseEnum::Done;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int, gold: int, purse_catalog_id: string}
     */
    private function reward(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BuyerQuest', $quest['rewards']) || ! is_array($quest['rewards']['BuyerQuest'])) {
            throw new RuntimeException('quest.rewards.BuyerQuest missing.');
        }

        $buyer = $quest['rewards']['BuyerQuest'];

        if (! array_key_exists('turnIn', $buyer) || ! is_array($buyer['turnIn'])) {
            throw new RuntimeException('BuyerQuest.turnIn missing.');
        }

        $row = $buyer['turnIn'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('BuyerQuest.turnIn.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('BuyerQuest.turnIn.silver missing.');
        }

        if (! array_key_exists('gold', $row) || ! is_int($row['gold'])) {
            throw new RuntimeException('BuyerQuest.turnIn.gold missing.');
        }

        if (! array_key_exists('purse_catalog_id', $row) || ! is_string($row['purse_catalog_id']) || $row['purse_catalog_id'] === '') {
            throw new RuntimeException('BuyerQuest.turnIn.purse_catalog_id missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'gold' => $row['gold'],
            'purse_catalog_id' => $row['purse_catalog_id'],
        ];
    }
}
