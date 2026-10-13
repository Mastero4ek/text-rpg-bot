<?php

declare(strict_types=1);

namespace App\Actions\DailyContract;

use App\Actions\Character\CharacterGrantExpAction;
use App\Actions\Character\CharacterGrantSilverAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Services\Bag\BagService;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DailyContractTurnInAction
{
    public function __construct(
        private readonly BagService $bag,
        private readonly CharacterGrantExpAction $grantExp,
        private readonly CharacterGrantSilverAction $grantSilver,
        private readonly DailyContractExpireStaleAction $expireStale,
    ) {}

    public function handle(
        Character $character,
        City $city,
        int $contractId,
        NpcEnum $issuer,
    ): ActionResult {
        return DB::transaction(function () use ($character, $city, $contractId, $issuer): ActionResult {
            $this->expireStale->handle($character->tg_id);

            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for DailyContractTurnIn.');
            }

            $contract = CharacterDailyContract::query()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if (! $contract instanceof CharacterDailyContract) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            if ($contract->tg_id !== $locked->tg_id) {
                return ActionResult::fail(__('telegram.daily.error.not_found'));
            }

            if ($contract->issuer_npc !== $issuer) {
                return ActionResult::fail(__('telegram.daily.error.wrong_issuer'));
            }

            $today = \Illuminate\Support\Facades\Date::now(config('app.timezone'))->toDateString();

            if ($contract->board_date->toDateString() !== $today) {
                return ActionResult::fail(__('telegram.daily.error.stale'));
            }

            if ($contract->type === DailyContractTypeEnum::Delivery) {
                if ($contract->target_city_id !== $city->id) {
                    return ActionResult::fail(__('telegram.daily.error.wrong_city'));
                }

                if ($contract->status !== DailyContractStatusEnum::Accepted) {
                    return ActionResult::fail(__('telegram.daily.error.not_ready'));
                }

                $bagKey = $contract->bag_catalog_id;

                if (! is_string($bagKey) || $bagKey === '') {
                    throw new RuntimeException('Delivery contract missing bag_catalog_id.');
                }

                if (! $this->bag->ownsQuestItem($locked->tg_id, $bagKey)) {
                    return ActionResult::fail(__('telegram.daily.error.no_parcel'));
                }

                $this->bag->removeQuestItem($locked->tg_id, $bagKey);
            } else {
                if ($contract->city_id !== $city->id) {
                    return ActionResult::fail(__('telegram.daily.error.wrong_city'));
                }

                if ($contract->status !== DailyContractStatusEnum::Ready) {
                    return ActionResult::fail(__('telegram.daily.error.not_ready'));
                }
            }

            if ($contract->reward_silver >= 1) {
                $locked = $this->grantSilver->handle($locked, $contract->reward_silver);
            }

            if ($contract->reward_exp >= 1) {
                $locked = $this->grantExp->handle($locked, $contract->reward_exp);
            }

            $contract->status = DailyContractStatusEnum::Completed;
            $contract->save();

            return ActionResult::ok($locked);
        });
    }
}
