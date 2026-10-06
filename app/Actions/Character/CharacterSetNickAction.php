<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\OnboardingService;
use App\Support\ActionResult;

final class CharacterSetNickAction
{
    public function __construct(
        private readonly OnboardingService $onboarding,
    ) {}

    public function handle(Character $character, string $raw): ActionResult
    {
        return $this->onboarding->setNick($character, $raw);
    }
}
