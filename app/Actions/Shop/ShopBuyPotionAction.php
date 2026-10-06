<?php

declare(strict_types=1);

namespace App\Actions\Shop;

use App\Services\Shop\ShopService;
use App\Support\ActionResult;

final class ShopBuyPotionAction
{
    public function __construct(
        private readonly ShopService $shop,
    ) {}

    public function handle(int $tgId): ActionResult
    {
        return $this->shop->buyPotion($tgId);
    }
}
