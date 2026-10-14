<?php

declare(strict_types=1);

namespace App\Jobs\DailyContract;

use App\Models\Character\Character;
use App\Services\DailyContract\DailyContractBoardPushService;
use App\Services\DailyContract\DailyContractRefreshService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Throwable;

final class DailyContractBoardPushJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $tgId,
    ) {}

    public function handle(
        DailyContractBoardPushService $push,
        DailyContractRefreshService $refresh,
    ): void {
        $character = Character::query()->find($this->tgId);

        if (! $character instanceof Character) {
            $this->releaseDailyPushSlot();

            return;
        }

        if (! $refresh->isEligible($character)) {
            $this->releaseDailyPushSlot();

            return;
        }

        try {
            $push->notify($character);
        } catch (Throwable $e) {
            $this->releaseDailyPushSlot();

            throw $e;
        }
    }

    public function uniqueId(): string
    {
        $today = Date::now(config('app.timezone'))->toDateString();

        return 'daily-push:' . $this->tgId . ':' . $today;
    }

    private function releaseDailyPushSlot(): void
    {
        $today = Date::now(config('app.timezone'))->toDateString();

        Cache::forget('daily-contract-push:' . $this->tgId . ':' . $today);
    }
}
