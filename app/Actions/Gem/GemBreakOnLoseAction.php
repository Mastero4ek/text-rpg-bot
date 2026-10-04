<?php

declare(strict_types=1);

namespace App\Actions\Gem;

use App\Models\Character;
use App\Services\Gem\GemService;

final class GemBreakOnLoseAction
{
    public function __construct(
        private readonly GemService $gems,
    ) {}

    /**
     * @return list<string>
     */
    public function handle(Character $character): array
    {
        return $this->gems->breakSocketedOnLose($character);
    }
}
