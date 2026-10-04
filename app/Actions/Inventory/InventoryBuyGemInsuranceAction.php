<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Inventory\GemService;
use App\Support\Game\ActionResult;

final class InventoryBuyGemInsuranceAction
{
    public function __construct(
        private readonly GemService $gems,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return $this->gems->buyInsurance($character);
    }
}
