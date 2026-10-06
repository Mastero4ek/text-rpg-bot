<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Backpack\RepairService;
use App\Support\ActionResult;

final class BackpackRepairVipAction
{
    public function __construct(
        private readonly RepairService $repair,
    ) {}

    public function handle(Character $character, int $backpackItemId): ActionResult
    {
        return $this->repair->repairVip($character, $backpackItemId);
    }
}
