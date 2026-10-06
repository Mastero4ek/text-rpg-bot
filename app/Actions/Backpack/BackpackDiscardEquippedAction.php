<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Support\ActionResult;

final class BackpackDiscardEquippedAction
{
    public function __construct(
        private readonly BackpackService $backpack,
    ) {}

    public function handle(Character $character, int $backpackItemId): ActionResult
    {
        return $this->backpack->discardEquipped($character, $backpackItemId);
    }
}
