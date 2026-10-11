<?php

declare(strict_types=1);

namespace App\Actions\Overseer;

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OverseerSubmitStatsAction
{
    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for OverseerSubmitStats.');
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

            return ActionResult::ok($locked);
        });
    }
}
