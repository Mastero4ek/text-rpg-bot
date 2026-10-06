<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\LoadoutService;
use App\Support\Game\ActionResult;

final class InventoryEquipAction
{
    public function __construct(
        private readonly LoadoutService $loadout,
    ) {}

    public function handle(Character $character, int $inventoryRowId): ActionResult
    {
        return $this->loadout->equip($character, $inventoryRowId);
    }
}
