<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\Equipment\SlotEnum;
use App\Models\Character;
use App\Services\Inventory\LoadoutService;
use App\Support\Game\ActionResult;

final class InventoryEquipToSlotAction
{
    public function __construct(
        private readonly LoadoutService $loadout,
    ) {}

    public function handle(Character $character, int $inventoryRowId, SlotEnum $slot): ActionResult
    {
        return $this->loadout->equipToSlot($character, $inventoryRowId, $slot);
    }
}
