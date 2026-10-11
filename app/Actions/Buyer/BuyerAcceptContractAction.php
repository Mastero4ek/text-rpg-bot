<?php

declare(strict_types=1);

namespace App\Actions\Buyer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use App\Services\Backpack\BackpackService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BuyerAcceptContractAction
{
    public function __construct(
        private readonly BackpackService $backpack,
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for BuyerAcceptContract.');
            }

            if ($locked->buyer_at_tavern || $locked->quest_phase !== QuestPhaseEnum::Buyer) {
                return ActionResult::fail(__('telegram.quest.buyer.error.already_done'));
            }

            if (! $locked->unlock_board) {
                return ActionResult::fail(__('telegram.quest.buyer.error.wrong_phase'));
            }

            $reward = $this->reward();
            $amuletId = $reward['amulet_catalog_id'];

            $amulet = BackpackItem::query()
                ->where('tg_id', $locked->tg_id)
                ->where('catalog_id', $amuletId)
                ->lockForUpdate()
                ->first();

            if (! $amulet instanceof BackpackItem) {
                return ActionResult::fail(__('telegram.quest.buyer.error.no_amulet'));
            }

            LoadoutSlot::query()
                ->where('backpack_item_id', $amulet->id)
                ->delete();

            $this->backpack->removeOne($amulet);

            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            $maxHp = $this->characters->maxHp($locked);
            $locked->current_hp = $this->characters->clampHp($locked->current_hp, $maxHp);
            $locked->bruiser_contract_active = true;
            $locked->unlock_arena = true;
            $locked->buyer_at_tavern = true;
            $locked->quest_phase = QuestPhaseEnum::Bruiser;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int, amulet_catalog_id: string}
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

        if (! array_key_exists('contract', $buyer) || ! is_array($buyer['contract'])) {
            throw new RuntimeException('BuyerQuest.contract missing.');
        }

        $row = $buyer['contract'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('BuyerQuest.contract.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('BuyerQuest.contract.silver missing.');
        }

        if (! array_key_exists('amulet_catalog_id', $row) || ! is_string($row['amulet_catalog_id']) || $row['amulet_catalog_id'] === '') {
            throw new RuntimeException('BuyerQuest.contract.amulet_catalog_id missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'amulet_catalog_id' => $row['amulet_catalog_id'],
        ];
    }
}
