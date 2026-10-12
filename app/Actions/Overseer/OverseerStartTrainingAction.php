<?php

declare(strict_types=1);

namespace App\Actions\Overseer;

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Services\CharacterService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OverseerStartTrainingAction
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for OverseerStartTraining.');
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

            if ($locked->overseer_train_step !== OverseerTrainStepEnum::Intro
                && $locked->overseer_train_step !== OverseerTrainStepEnum::Fight) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_step'));
            }

            $locked = $this->characters->applyRegen($locked);
            $locked->current_hp = $this->characters->maxHp($locked);
            $locked->last_hp_update = now();
            $locked->save();

            $soldier = $this->enemies->makeFromCatalog(
                $this->enemies->tutorialCatalog(),
                $locked,
            );
            $this->fights->createTutorial($locked, $soldier);
            $locked->overseer_train_step = OverseerTrainStepEnum::Fight;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }
}
