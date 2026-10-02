<?php

declare(strict_types=1);

namespace App\Actions\Shop\BuyWeapon;

use App\Services\Shop\ShopService;
use App\Support\Game\ActionResult;

final class ShopBuyWeaponAction
{
    public function __construct(
        private readonly ShopService $shop,
    ) {}

    public function handle(int $tgId, string $itemId): ActionResult
    {
        return $this->shop->buyWeapon($tgId, $itemId);
    }
}
