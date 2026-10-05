<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Character;
use App\Services\Shop\ShopService;
use App\Support\Game\ActionResult;

final class InventorySellAction
{
    public function __construct(
        private readonly ShopService $shop,
    ) {}

    public function handle(Character $character, int $inventoryRowId): ActionResult
    {
        return $this->shop->sell($character, $inventoryRowId);
    }
}
