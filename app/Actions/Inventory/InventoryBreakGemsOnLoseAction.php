<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\GemService;

final class InventoryBreakGemsOnLoseAction
{
    public function __construct(
        private readonly GemService $gems,
    ) {}

    /**
     * @return list<string>
     */
    public function handle(Character $character): array
    {
        return $this->gems->breakSocketedOnLose($character);
    }
}
