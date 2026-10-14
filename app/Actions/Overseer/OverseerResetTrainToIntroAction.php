<?php

declare(strict_types=1);

namespace App\Actions\Overseer;

use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Services\Character\CharacterService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OverseerResetTrainToIntroAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for OverseerResetTrainToIntro.');
            }

            if ($locked->overseer_trained || $locked->quest_phase !== QuestPhaseEnum::Overseer) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_phase'));
            }

            if ($locked->overseer_train_step !== OverseerTrainStepEnum::Fight
                && $locked->overseer_train_step !== OverseerTrainStepEnum::Intro) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_step'));
            }

            $locked = $this->characters->applyRegen($locked);
            $maxHp = $this->characters->maxHp($locked);
            $maxStamina = $this->characters->maxStamina($locked);
            $locked->current_hp = $maxHp;
            $locked->current_stamina = $maxStamina;
            $locked->last_hp_update = now();
            $locked->last_stamina_update = now();
            $locked->overseer_train_step = OverseerTrainStepEnum::Intro;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }
}
