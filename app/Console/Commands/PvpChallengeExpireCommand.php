<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Pvp\PvpChallengeExpireAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pvp:expire-challenges')]
#[Description('Mark expired open PvP challenges as EXPIRED')]
final class PvpChallengeExpireCommand extends Command
{
    public function handle(PvpChallengeExpireAction $expire): int
    {
        $count = $expire->handle();
        $this->info('Expired challenges: ' . $count);

        return self::SUCCESS;
    }
}
