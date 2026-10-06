<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Enums\Equipment\SlotEnum;
use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Support\ActionResult;

final class BackpackEquipToSlotAction
{
    public function __construct(
        private readonly LoadoutService $loadout,
    ) {}

    public function handle(Character $character, int $backpackItemId, SlotEnum $slot): ActionResult
    {
        return $this->loadout->equipToSlot($character, $backpackItemId, $slot);
    }
}
