<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\Character\CharacterService;
use InvalidArgumentException;

final class CharacterGrantStatPointsAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character, int $amount): Character
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('Stat points amount must be >= 1.');
        }

        return $this->characters->grantStatPoints($character, $amount);
    }
}
