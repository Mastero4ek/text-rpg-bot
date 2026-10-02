<?php

declare(strict_types=1);

namespace App\Actions\Character\SetNick;

use App\Models\Character;
use App\Services\Onboarding\OnboardingService;
use App\Support\Game\ActionResult;

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
