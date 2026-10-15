<?php

declare(strict_types=1);

namespace App\Services\Pvp;

use App\Models\Character\Character;
use App\Models\Fight;

final readonly class PvpDuelOutcome
{
    private function __construct(
        public string $kind,
        public ?Character $characterA,
        public ?Fight $fightA,
        public ?Character $characterB,
        public ?Fight $fightB,
        public ?int $winnerTgId,
    ) {}

    public static function missing(): self
    {
        return new self('missing', null, null, null, null, null);
    }

    public static function waiting(Character $character, Fight $fight): self
    {
        return new self('waiting', $character, $fight, null, null, null);
    }

    public static function continueFight(
        Character $characterA,
        Fight $fightA,
        Character $characterB,
        Fight $fightB,
    ): self {
        return new self('continue', $characterA, $fightA, $characterB, $fightB, null);
    }

    public static function finished(
        Character $characterA,
        Fight $fightA,
        Character $characterB,
        Fight $fightB,
        int $winnerTgId,
    ): self {
        return new self('finished', $characterA, $fightA, $characterB, $fightB, $winnerTgId);
    }

    public static function aborted(
        Character $characterA,
        Fight $fightA,
        Character $characterB,
        Fight $fightB,
    ): self {
        return new self('aborted', $characterA, $fightA, $characterB, $fightB, null);
    }
}
