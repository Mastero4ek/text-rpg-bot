<?php

declare(strict_types=1);

namespace App\Actions\DailyContract;

use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\DailyContract\DailyContractTypeEnum;
use App\Models\Character\CharacterDailyContract;
use App\Services\Bag\BagService;

final class DailyContractExpireStaleAction
{
    public function __construct(
        private readonly BagService $bag,
    ) {}

    public function handle(int $tgId): void
    {
        $today = \Illuminate\Support\Facades\Date::now(config('app.timezone'))->toDateString();

        $rows = CharacterDailyContract::query()
            ->where('tg_id', $tgId)
            ->where('board_date', '<', $today)
            ->whereIn('status', [
                DailyContractStatusEnum::Accepted->value,
                DailyContractStatusEnum::Ready->value,
            ])
            ->get();

        foreach ($rows as $row) {
            if (
                $row->type === DailyContractTypeEnum::Delivery
                && is_string($row->bag_catalog_id)
                && $row->bag_catalog_id !== ''
            ) {
                $this->bag->removeQuestItem($tgId, $row->bag_catalog_id);
            }

            $row->status = DailyContractStatusEnum::Expired;
            $row->save();
        }
    }
}
