<?php

declare(strict_types=1);

namespace App\Actions\Overseer;

use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OverseerAdvanceToStatsAction
{
    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for OverseerAdvanceToStats.');
            }

            if ($locked->overseer_trained || $locked->quest_phase !== QuestPhaseEnum::Overseer) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_phase'));
            }

            if ($locked->overseer_train_step !== OverseerTrainStepEnum::Fight) {
                return ActionResult::fail(__('telegram.quest.overseer.error.wrong_step'));
            }

            $locked->overseer_train_step = OverseerTrainStepEnum::Stats;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }
}
