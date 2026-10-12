<?php

declare(strict_types=1);

namespace App\Actions\Bruiser;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Quests\Chapter1\Chapter1ScreenProjector;
use App\Services\Backpack\BackpackService;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BruiserCompleteAction
{
    public function __construct(
        private readonly BackpackService $backpack,
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
                throw new RuntimeException('Character missing for BruiserComplete.');
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
            $knuckleId = $reward['knuckle_catalog_id'];

            if (! $this->bag->canAcceptQuestItem($locked, $purseId)) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.bag_full'));
            }

            if (! $this->bag->ownsQuestItem($locked->tg_id, $purseId)) {
                $this->bag->addQuestItem($locked->tg_id, $purseId);
            }

            $needKnuckle = ! $this->backpack->owns($locked->tg_id, $knuckleId);
            $knucklePending = false;

            if ($needKnuckle) {
                $free = $this->backpack->maxRows($locked) - $this->backpack->rowCount($locked->tg_id);

                if ($free < 1) {
                    $knucklePending = true;
                } else {
                    $this->backpack->addItem($locked->tg_id, $knuckleId);
                }
            }

            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            $maxHp = $this->characters->maxHp($locked);
            $maxStamina = $this->characters->maxStamina($locked);
            if ($knucklePending) {
                $this->projector->writeAfterBruiserCompleteKnucklePending($locked);
            } else {
                $this->projector->writeAfterBruiserCompleteReady($locked);
            }
            $locked->current_hp = $maxHp;
            $locked->current_stamina = $maxStamina;
            $locked->last_hp_update = now();
            $locked->last_stamina_update = now();
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int, purse_catalog_id: string, knuckle_catalog_id: string}
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

        if (! array_key_exists('knuckle_catalog_id', $row) || ! is_string($row['knuckle_catalog_id']) || $row['knuckle_catalog_id'] === '') {
            throw new RuntimeException('BruiserQuest.knuckle_catalog_id missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
            'purse_catalog_id' => $row['purse_catalog_id'],
            'knuckle_catalog_id' => $row['knuckle_catalog_id'],
        ];
    }
}
