<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Character;
use App\Services\CharacterService;

final class OnboardingFillHpAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character): Character
    {
        $character = $this->characters->applyRegen($character);
        $character->current_hp = $this->characters->maxHp($character);
        $character->save();

        return $character;
    }
}
