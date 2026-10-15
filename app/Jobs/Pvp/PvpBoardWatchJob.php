<?php

declare(strict_types=1);

namespace App\Jobs\Pvp;

use App\Services\Pvp\PvpBoardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PvpBoardWatchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $tgId,
        public int $seq,
    ) {}

    public function handle(PvpBoardService $board): void
    {
        $board->tick($this->tgId, $this->seq);
    }
}
