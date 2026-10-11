<?php

declare(strict_types=1);

namespace App\Actions\Healer;

use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HealerOpenMeetAction
{
    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for HealerOpenMeet.');
            }

            if (
                $locked->quest_phase !== QuestPhaseEnum::Healer
                || ! $locked->unlock_portal
                || $locked->healer_at_tavern
            ) {
                return ActionResult::fail(__('telegram.quest.healer.error.wrong_phase'));
            }

            if ($locked->healer_meet_open) {
                return ActionResult::ok($locked);
            }

            $locked->healer_meet_open = true;
            $locked->save();

            return ActionResult::ok($locked);
        });
    }
}
