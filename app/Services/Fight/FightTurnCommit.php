<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Combat\ZoneEnum;
use App\Models\Character;
use App\Models\Fight;

final readonly class FightTurnCommit
{
    private function __construct(
        public string $kind,
        public ?Character $character,
        public ?Fight $fight,
        public ?ZoneEnum $firstDefend,
    ) {}

    public static function noop(): self
    {
        return new self('noop', null, null, null);
    }

    public static function potionDenied(Character $character, Fight $fight): self
    {
        return new self('potion_denied', $character, $fight, null);
    }

    public static function attack(Character $character, Fight $fight): self
    {
        return new self('attack', $character, $fight, null);
    }

    public static function attackSecond(Character $character, Fight $fight): self
    {
        return new self('attack_second', $character, $fight, null);
    }

    public static function defend(Character $character, Fight $fight): self
    {
        return new self('defend', $character, $fight, null);
    }

    public static function defendSecond(Character $character, Fight $fight, ZoneEnum $first): self
    {
        return new self('defend_second', $character, $fight, $first);
    }

    public static function runRound(Character $character): self
    {
        return new self('run_round', $character, null, null);
    }
}
