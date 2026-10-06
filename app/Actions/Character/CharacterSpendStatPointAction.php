<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\CharacterService;
use App\Support\ActionResult;

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
