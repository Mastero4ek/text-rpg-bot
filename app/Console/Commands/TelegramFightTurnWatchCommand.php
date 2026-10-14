<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FightTurnWatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

final class TelegramFightTurnWatchCommand extends Command
{
    protected $signature = 'telegram:fight-turn-watch {tgId} {turnSeq} {sleepSeconds}';

    protected $description = 'Refresh fight turn timer or skip the turn after the deadline.';

    public function handle(): int
    {
        $sleepSeconds = (int) $this->argument('sleepSeconds');

        if ($sleepSeconds > 0) {
            Sleep::for($sleepSeconds)->seconds();
        }

        app()->call([
            new FightTurnWatchJob(
                (int) $this->argument('tgId'),
                (int) $this->argument('turnSeq'),
            ),
            'handle',
        ]);

        return self::SUCCESS;
    }
}
