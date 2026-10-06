<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Support\Game\ActionResult;

final class BackpackEquipAction
{
    public function __construct(
        private readonly LoadoutService $loadout,
    ) {}

    public function handle(Character $character, int $backpackItemId): ActionResult
    {
        return $this->loadout->equip($character, $backpackItemId);
    }
}
