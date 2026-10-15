<?php

declare(strict_types=1);

namespace App\Actions\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\Character\Character;
use App\Models\Pvp\PvpChallenge;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PvpChallengeCancelAction
{
    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for PvpChallengeCancel.');
            }

            $challenge = PvpChallenge::query()
                ->where('challenger_tg_id', $locked->tg_id)
                ->where('status', PvpChallengeStatusEnum::Open)
                ->lockForUpdate()
                ->first();

            if (! $challenge instanceof PvpChallenge) {
                return ActionResult::fail(__('errors.pvp_no_challenge'));
            }

            $challenge->status = PvpChallengeStatusEnum::Cancelled;
            $challenge->save();

            return ActionResult::ok($locked);
        });
    }
}
