<?php

declare(strict_types=1);

namespace App\Actions\Gem;

use App\Models\Character;
use App\Services\Gem\GemService;
use App\Support\Game\ActionResult;

final class GemDiscardFromPouchAction
{
    public function __construct(
        private readonly GemService $gems,
    ) {}

    public function handle(Character $character, int $pouchIndex): ActionResult
    {
        return $this->gems->discardFromPouch($character, $pouchIndex);
    }
}
