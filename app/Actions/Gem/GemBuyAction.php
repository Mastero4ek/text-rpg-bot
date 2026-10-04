<?php

declare(strict_types=1);

namespace App\Actions\Gem;

use App\Models\Character;
use App\Services\Gem\GemService;
use App\Support\Game\ActionResult;

final class GemBuyAction
{
    public function __construct(
        private readonly GemService $gems,
    ) {}

    public function handle(Character $character, string $gemId): ActionResult
    {
        return $this->gems->buy($character, $gemId);
    }
}
