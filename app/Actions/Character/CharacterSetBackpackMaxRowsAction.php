<?php

declare(strict_types=1);

namespace App\Actions\Character;

use App\Models\Character;
use App\Services\Character\CharacterService;
use InvalidArgumentException;

final class CharacterSetBackpackMaxRowsAction
{
    public function __construct(
        private readonly CharacterService $characters,
    ) {}

    public function handle(Character $character, int $maxRows): Character
    {
        if ($maxRows < 1) {
            throw new InvalidArgumentException('Backpack max rows must be >= 1.');
        }

        return $this->characters->setBackpackMaxRows($character, $maxRows);
    }
}
