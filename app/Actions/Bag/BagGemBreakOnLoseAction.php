<?php

declare(strict_types=1);

namespace App\Actions\Bag;

use App\Models\Character;
use App\Services\Bag\BagService;

final class BagGemBreakOnLoseAction
{
    public function __construct(
        private readonly BagService $bag,
    ) {}

    /**
     * @return list<string>
     */
    public function handle(Character $character): array
    {
        return $this->bag->breakSocketedOnLose($character);
    }
}
