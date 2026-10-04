<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\InventoryService;

final class InventoryApplyFightWearAction
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @return list<string>
     */
    public function handleAfterLose(Character $character, int $pierceCount): array
    {
        return $this->inventory->applyFightWearAfterLose($character, $pierceCount);
    }

    /**
     * @return list<string>
     */
    public function handleAfterWin(Character $character, int $pierceCount): array
    {
        return $this->inventory->applyFightWearAfterWin($character, $pierceCount);
    }
}
