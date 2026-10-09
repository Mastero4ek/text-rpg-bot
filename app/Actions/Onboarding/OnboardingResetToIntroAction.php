<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\ProgressStepEnum;
use App\Models\Character;

final class OnboardingResetToIntroAction
{
    public function handle(Character $character): Character
    {
        $character->progress_step = ProgressStepEnum::INTRO;
        $character->save();

        return $character;
    }
}
