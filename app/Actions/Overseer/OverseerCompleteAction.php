<?php

declare(strict_types=1);

namespace App\Actions\Overseer;

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OverseerCompleteAction
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for OverseerComplete.');
            }

            if ($locked->overseer_trained) {
                return ActionResult::fail(__('telegram.quest.overseer.error.already_trained'));
            }

            if ($locked->quest_phase !== QuestPhaseEnum::Overseer) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_phase'));
            }

            if ($locked->progress_step !== ProgressStepEnum::ARRIVED) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_phase'));
            }

            if ($locked->overseer_train_step !== OverseerTrainStepEnum::Stats) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_step'));
            }

            if ($locked->stat_points > 0) {
                return ActionResult::fail(__('errors.spend_all_points'));
            }

            $reward = $this->reward();
            $this->characters->addExpSilver($locked, $reward['exp'], $reward['silver']);
            $locked = $this->characters->findByTgId($locked->tg_id);

            $maxHp = $this->characters->maxHp($locked);
            $maxStamina = $this->characters->maxStamina($locked);
            $locked->overseer_trained = true;
            $locked->overseer_at_tavern = true;
            $locked->overseer_train_step = null;
            $locked->unlock_portal = true;
            $locked->quest_phase = QuestPhaseEnum::Healer;
            $locked->progress_step = ProgressStepEnum::DONE;
            $locked->current_hp = $maxHp;
            $locked->current_stamina = $maxStamina;
            $locked->last_hp_update = now();
            $locked->last_stamina_update = now();
            $locked->save();

            return ActionResult::ok($locked);
        });
    }

    /**
     * @return array{exp: int, silver: int}
     */
    private function reward(): array
    {
        $quest = $this->config->quest();

        if (! array_key_exists('rewards', $quest) || ! is_array($quest['rewards'])) {
            throw new RuntimeException('quest.rewards missing.');
        }

        if (! array_key_exists('OverseerQuest', $quest['rewards']) || ! is_array($quest['rewards']['OverseerQuest'])) {
            throw new RuntimeException('quest.rewards.OverseerQuest missing.');
        }

        $row = $quest['rewards']['OverseerQuest'];

        if (! array_key_exists('exp', $row) || ! is_int($row['exp'])) {
            throw new RuntimeException('OverseerQuest.exp missing.');
        }

        if (! array_key_exists('silver', $row) || ! is_int($row['silver'])) {
            throw new RuntimeException('OverseerQuest.silver missing.');
        }

        return [
            'exp' => $row['exp'],
            'silver' => $row['silver'],
        ];
    }
}
