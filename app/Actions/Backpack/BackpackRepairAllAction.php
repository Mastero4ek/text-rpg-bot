<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Backpack\RepairService;
use App\Support\Game\ActionResult;

final class BackpackRepairAllAction
{
    public function __construct(
        private readonly RepairService $repair,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return $this->repair->repairAll($character);
    }
}
