<?php

declare(strict_types=1);

namespace App\Actions\Pvp;

use App\Enums\Pvp\PvpChallengeStatusEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Pvp\PvpChallenge;
use App\Services\Pvp\PvpChallengeService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PvpChallengeCreateAction
{
    public function __construct(
        private readonly PvpChallengeService $challenges,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for PvpChallengeCreate.');
            }

            if ($locked->city_id === null) {
                return ActionResult::fail(__('errors.city_unavailable'));
            }

            $city = City::query()->lockForUpdate()->find($locked->city_id);

            if (! $city instanceof City || ! $city->has_fights_list) {
                return ActionResult::fail(__('errors.city_unavailable'));
            }

            if (! $this->challenges->canEnterBoard($locked)) {
                if (! $this->challenges->hasEnoughHp($locked)) {
                    return ActionResult::fail(__('errors.pvp_hp_low'));
                }

                return ActionResult::fail(__('errors.pvp_busy'));
            }

            $existing = PvpChallenge::query()
                ->where('challenger_tg_id', $locked->tg_id)
                ->where('status', PvpChallengeStatusEnum::Open)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PvpChallenge) {
                return ActionResult::fail(__('errors.pvp_already_challenging'));
            }

            $challenge = new PvpChallenge;
            $challenge->challenger_tg_id = $locked->tg_id;
            $challenge->city_id = $city->id;
            $challenge->stake_silver = 0;
            $challenge->stake_gold = 0;
            $challenge->status = PvpChallengeStatusEnum::Open;
            $challenge->expires_at = now()->addSeconds($this->challenges->challengeTtlSeconds());
            $challenge->save();

            return ActionResult::ok($locked);
        });
    }
}
