<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\CharacterService;

final class CharacterResetStatsAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character): Character
    {
        return $this->characters->resetStats($character);
    }
}
