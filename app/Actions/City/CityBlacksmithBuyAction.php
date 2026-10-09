<?php

declare(strict_types=1);

namespace App\Actions\City;

use App\Services\City\BlacksmithService;
use App\Support\ActionResult;

final class CityBlacksmithBuyAction
{
    public function __construct(
        private readonly BlacksmithService $blacksmith,
    ) {}

    public function handle(int $tgId, string $catalogId): ActionResult
    {
        return $this->blacksmith->buyCatalogItem($tgId, $catalogId);
    }
}
