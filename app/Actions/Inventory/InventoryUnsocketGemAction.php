<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\GemService;
use App\Support\Game\ActionResult;

final class InventoryUnsocketGemAction
{
    public function __construct(
        private readonly GemService $gems,
    ) {}

    public function handle(Character $character, int $inventoryRowId, int $socketIndex): ActionResult
    {
        return $this->gems->unsocket($character, $inventoryRowId, $socketIndex);
    }
}
