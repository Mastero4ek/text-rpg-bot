<?php

declare(strict_types=1);

namespace App\Jobs\Pvp;

use App\Actions\Pvp\PvpChallengeExpireAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PvpChallengeExpireJob implements ShouldQueue
{
    use Queueable;

    public function handle(PvpChallengeExpireAction $expire): void
    {
        $expire->handle();
    }
}
