<?php

declare(strict_types=1);

namespace App\Actions\Bruiser;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Services\Backpack\BackpackService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BruiserTakeKnuckleAction
{
    public function __construct(
        private readonly BackpackService $backpack,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for BruiserTakeKnuckle.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Bruiser
                || ! $locked->bruiser_knuckle_pending
                || $locked->bruiser_contract_active
            ) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.wrong_phase'));
            }

            $knuckleId = $this->knuckleCatalogId();

            if ($this->backpack->owns($locked->tg_id, $knuckleId)) {
                $locked->bruiser_knuckle_pending = false;
                $locked->save();

                return ActionResult::ok($locked);
            }

            $free = $this->backpack->maxRows($locked) - $this->backpack->rowCount($locked->tg_id);

            if ($free < 1) {
                return ActionResult::fail(__('telegram.quest.bruiser.error.backpack_full'));
            }

            $this->backpack->addItem($locked->tg_id, $knuckleId);
            $locked->bruiser_knuckle_pending = false;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    private function knuckleCatalogId(): string
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('BruiserQuest', $quest['rewards']) || ! is_array($quest['rewards']['BruiserQuest'])) {
            throw new RuntimeException('quest.rewards.BruiserQuest missing.');
        }

        $row = $quest['rewards']['BruiserQuest'];

        if (! array_key_exists('knuckle_catalog_id', $row) || ! is_string($row['knuckle_catalog_id']) || $row['knuckle_catalog_id'] === '') {
            throw new RuntimeException('BruiserQuest.knuckle_catalog_id missing.');
        }

        return $row['knuckle_catalog_id'];
    }
}
