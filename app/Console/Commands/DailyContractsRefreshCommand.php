<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DailyContract\DailyContractRefreshService;
use Illuminate\Console\Command;

final class DailyContractsRefreshCommand extends Command
{
    protected $signature = 'daily-contracts:refresh';

    protected $description = 'Materialize daily contract boards for eligible characters and queue Telegram pushes.';

    public function handle(DailyContractRefreshService $refresh): int
    {
        $dispatched = $refresh->dispatchRefreshJobs();

        $this->info('Dispatched daily contract refresh jobs: ' . $dispatched);

        return self::SUCCESS;
    }
}
