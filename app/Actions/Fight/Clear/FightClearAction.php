<?php

declare(strict_types=1);

namespace App\Actions\Fight\Clear;

use App\Services\Fight\FightService;

final class FightClearAction
{
    public function __construct(
        private readonly FightService $fights,
    ) {}

    public function handle(int $tgId): void
    {
        $this->fights->clear($tgId);
    }
}
