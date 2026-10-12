<?php

declare(strict_types=1);

namespace App\Actions\DailyContract;

use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\Fight;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DailyContractMarkReadyAction
{
    public function __construct(
        private readonly DailyContractExpireStaleAction $expireStale,
    ) {}

    public function handle(Character $character, Fight $fight): ActionResult
    {
        return DB::transaction(function () use ($character, $fight): ActionResult {
            $this->expireStale->handle($character->tg_id);

            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for DailyContractMarkReady.');
            }

            if ($fight->daily_contract_id === null) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            $contract = CharacterDailyContract::query()
                ->whereKey($fight->daily_contract_id)
                ->lockForUpdate()
                ->first();

            if (! $contract instanceof CharacterDailyContract) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            if ($contract->tg_id !== $locked->tg_id) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            $today = \Illuminate\Support\Facades\Date::now(config('app.timezone'))->toDateString();

            if ($contract->board_date->toDateString() !== $today) {
                return ActionResult::fail(__('telegram.daily.error.stale'));
            }

            if ($contract->status !== DailyContractStatusEnum::Accepted) {
                return ActionResult::fail(__('telegram.daily.error.not_accepted'));
            }

            $contract->status = DailyContractStatusEnum::Ready;
            $contract->save();

            return ActionResult::ok($locked);
        });
    }
}
