<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\InventoryService;
use App\Support\Game\ActionResult;

final class InventoryRepairAllAction
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return $this->inventory->repairAll($character);
    }
}
