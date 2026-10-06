<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Support\ActionResult;

final class BackpackUnequipAction
{
    public function __construct(
        private readonly LoadoutService $loadout,
    ) {}

    public function handle(Character $character, int $backpackItemId): ActionResult
    {
        return $this->loadout->unequip($character, $backpackItemId);
    }
}
