<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IdleSessionResetJob;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

final class TelegramIdleSessionResetCommand extends Command
{
    protected $signature = 'telegram:idle-session-reset {tgId} {actionAtUnix} {sleepSeconds}';

    protected $description = 'Send idle session panel after the player has been inactive.';

    public function handle(): int
    {
        $sleepSeconds = (int) $this->argument('sleepSeconds');

        if ($sleepSeconds > 0) {
            Sleep::for($sleepSeconds)->seconds();
        }

        app()->call([
            new IdleSessionResetJob(
                (int) $this->argument('tgId'),
                (int) $this->argument('actionAtUnix'),
            ),
            'handle',
        ]);

        return self::SUCCESS;
    }
}
