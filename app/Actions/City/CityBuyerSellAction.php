<?php

declare(strict_types=1);

namespace App\Actions\City;

use App\Models\Character;
use App\Services\City\BuyerService;
use App\Support\ActionResult;

final class CityBuyerSellAction
{
    public function __construct(
        private readonly BuyerService $buyer,
    ) {}

    public function handleBackpack(Character $character, int $backpackItemId): ActionResult
    {
        return $this->buyer->sellBackpackItem($character, $backpackItemId);
    }

    public function handleBag(Character $character, int $bagItemId): ActionResult
    {
        return $this->buyer->sellBagItem($character, $bagItemId);
    }
}
