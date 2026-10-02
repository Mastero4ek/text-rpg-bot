<?php

declare(strict_types=1);

namespace App\Actions\Inventory\Equip;

use App\Models\Character;
use App\Services\Inventory\InventoryService;
use App\Support\Game\ActionResult;

final class InventoryEquipAction
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function handle(Character $character, int $inventoryRowId): ActionResult
    {
        return $this->inventory->equip($character, $inventoryRowId);
    }
}
