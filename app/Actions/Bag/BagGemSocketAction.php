<?php

declare(strict_types=1);

namespace App\Actions\Bag;

use App\Models\Character;
use App\Services\Bag\BagService;
use App\Support\ActionResult;

final class BagGemSocketAction
{
    public function __construct(
        private readonly BagService $bag,
    ) {}

    public function handle(Character $character, int $backpackItemId, int $bagItemId): ActionResult
    {
        return $this->bag->socket($character, $backpackItemId, $bagItemId);
    }
}
