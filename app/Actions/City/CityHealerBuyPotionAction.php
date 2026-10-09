<?php

declare(strict_types=1);

namespace App\Actions\City;

use App\Services\City\HealerService;
use App\Support\ActionResult;

final class CityHealerBuyPotionAction
{
    public function __construct(
        private readonly HealerService $healer,
    ) {}

    public function handle(int $tgId, string $catalogId): ActionResult
    {
        return $this->healer->buyPotion($tgId, $catalogId);
    }
}
