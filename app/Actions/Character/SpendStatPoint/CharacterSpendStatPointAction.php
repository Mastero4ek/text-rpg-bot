<?php

declare(strict_types=1);

namespace App\Actions\Character\SpendStatPoint;

use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Support\Game\ActionResult;

final class CharacterSpendStatPointAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character, string $stat): ActionResult
    {
        return $this->characters->spendStatPoint($character, $stat);
    }
}
