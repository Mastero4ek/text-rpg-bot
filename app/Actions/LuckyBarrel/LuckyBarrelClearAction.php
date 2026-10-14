<?php

declare(strict_types=1);

namespace App\Actions\LuckyBarrel;

use App\Services\LuckyBarrel\LuckyBarrelService;

final class LuckyBarrelClearAction
{
    public function __construct(
        private readonly LuckyBarrelService $luckyBarrels,
    ) {}

    public function handle(int $tgId): void
    {
        $this->luckyBarrels->clear($tgId);
    }
}
