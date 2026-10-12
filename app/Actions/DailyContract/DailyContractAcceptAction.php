<?php

declare(strict_types=1);

namespace App\Actions\DailyContract;

use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Services\Bag\BagService;
use App\Services\GameConfig;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DailyContractAcceptAction
{
    public function __construct(
        private readonly BagService $bag,
        private readonly DailyContractExpireStaleAction $expireStale,
        private readonly GameConfig $config,
    ) {}

    public function handle(Character $character, City $city, int $contractId): ActionResult
    {
        return DB::transaction(function () use ($character, $city, $contractId): ActionResult {
            $this->expireStale->handle($character->tg_id);

            $locked = Character::query()->whereKey($character->tg_id)->lockForUpdate()->first();

            if (! $locked instanceof Character) {
                throw new RuntimeException('Character missing for DailyContractAccept.');
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

            if ($contract->city_id !== $city->id) {
                return ActionResult::fail(__('telegram.daily.error.wrong_city'));
            }

            $today = \Illuminate\Support\Facades\Date::now(config('app.timezone'))->toDateString();

            if ($contract->board_date->toDateString() !== $today) {
                return ActionResult::fail(__('telegram.daily.error.stale'));
            }

            if ($contract->status !== DailyContractStatusEnum::Offered) {
                return ActionResult::fail(__('telegram.daily.error.not_offered'));
            }

            $taken = CharacterDailyContract::query()
                ->where('tg_id', $locked->tg_id)
                ->whereDate('board_date', $today)
                ->whereIn('status', [
                    DailyContractStatusEnum::Accepted->value,
                    DailyContractStatusEnum::Ready->value,
                    DailyContractStatusEnum::Completed->value,
                ])
                ->count();

            if ($taken >= $this->maxAcceptsPerDay()) {
                return ActionResult::fail(__('telegram.daily.error.accept_limit'));
            }

            if ($contract->type === DailyContractTypeEnum::Delivery) {
                $bagKey = $contract->bag_catalog_id;

                if (! is_string($bagKey) || $bagKey === '') {
                    throw new RuntimeException('Delivery contract missing bag_catalog_id.');
                }

                if (! $this->bag->canAcceptQuestItem($locked, $bagKey)) {
                    return ActionResult::fail(__('telegram.daily.error.bag_full'));
                }

                $this->bag->addQuestItem($locked->tg_id, $bagKey);
            }

            $contract->status = DailyContractStatusEnum::Accepted;
            $contract->save();

            return ActionResult::ok($locked->fresh());
        });
    }

    private function maxAcceptsPerDay(): int
    {
        $cfg = $this->config->dailyContracts();

        if (! array_key_exists('max_accepts_per_day', $cfg) || ! is_int($cfg['max_accepts_per_day'])) {
            throw new RuntimeException('daily_contracts.max_accepts_per_day missing.');
        }

        if ($cfg['max_accepts_per_day'] < 1) {
            throw new RuntimeException('daily_contracts.max_accepts_per_day must be >= 1.');
        }

        return $cfg['max_accepts_per_day'];
    }
}
