<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Support\Game\ActionResult;

final class CharacterResetStatsForGoldAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character): ActionResult
    {
        return $this->characters->resetStatsForGold($character);
    }
}
