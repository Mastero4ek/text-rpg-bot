<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Models\Character;
use App\Models\Fight;

final readonly class FightRoundOutcome
{
    private function __construct(
        public string $kind,
        public ?Character $character,
        public ?Fight $fight,
    ) {}

    public static function missing(): self
    {
        return new self('missing', null, null);
    }

    public static function win(Character $character, Fight $fight): self
    {
        return new self('win', $character, $fight);
    }

    public static function lose(Character $character, Fight $fight): self
    {
        return new self('lose', $character, $fight);
    }

    public static function continueFight(Character $character, Fight $fight): self
    {
        return new self('continue', $character, $fight);
    }
}
