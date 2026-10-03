<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\Onboarding\OnboardingService;
use App\Support\Game\ActionResult;

final class CharacterSetLocationAction
{
    public function __construct(
        private readonly OnboardingService $onboarding,
    ) {}

    public function handle(Character $character, string $location): ActionResult
    {
        return $this->onboarding->setLocation($character, $location);
    }
}
