<?php

declare(strict_types=1);

namespace App\Jobs\DailyContract;

use App\Services\DailyContract\DailyContractRefreshService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;

final class DailyContractRefreshCharacterJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $tgId,
    ) {}

    public function handle(DailyContractRefreshService $refresh): void
    {
        $refresh->refreshCharacter($this->tgId);
    }

    public function uniqueId(): string
    {
        $today = Date::now(config('app.timezone'))->toDateString();

        return 'daily-refresh:' . $this->tgId . ':' . $today;
    }
}
