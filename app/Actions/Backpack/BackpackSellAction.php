<?php

declare(strict_types=1);

namespace App\Actions\Backpack;

use App\Models\Character;
use App\Services\Shop\ShopService;
use App\Support\ActionResult;

final class BackpackSellAction
{
    public function __construct(
        private readonly ShopService $shop,
    ) {}

    public function handle(Character $character, int $backpackItemId): ActionResult
    {
        return $this->shop->sell($character, $backpackItemId);
    }
}
