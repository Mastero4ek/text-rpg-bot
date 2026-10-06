<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\RepairService;
use App\Support\Game\ActionResult;

final class InventoryRepairAction
{
    public function __construct(
        private readonly RepairService $repair,
    ) {}

    public function handle(Character $character, int $inventoryRowId): ActionResult
    {
        return $this->repair->repair($character, $inventoryRowId);
    }
}
