<?php

declare(strict_types=1);

namespace App\Support\LuckyBarrel;

use App\Enums\LuckyBarrel\LuckyBarrelOutcomeEnum;
use App\Models\Character\Character;
use App\Models\LuckyBarrel;

final readonly class LuckyBarrelResult
{
    public function __construct(
        public bool $ok,
        public ?string $error,
        public ?Character $character,
        public ?LuckyBarrelOutcomeEnum $outcome,
        public ?LuckyBarrel $session,
        public ?int $payout,
        public ?int $stake,
        public ?int $ratIndex,
    ) {}

    public static function fail(string $error): self
    {
        return new self(false, $error, null, null, null, null, null, null);
    }

    public static function noop(Character $character, LuckyBarrel $session): self
    {
        return new self(true, null, $character, LuckyBarrelOutcomeEnum::Noop, $session, null, null, null);
    }

    public static function play(Character $character, LuckyBarrel $session): self
    {
        return new self(true, null, $character, LuckyBarrelOutcomeEnum::Play, $session, null, null, null);
    }

    public static function win(Character $character, int $payout, int $stake): self
    {
        return new self(true, null, $character, LuckyBarrelOutcomeEnum::Win, null, $payout, $stake, null);
    }

    public static function winMax(Character $character, int $payout, int $stake): self
    {
        return new self(true, null, $character, LuckyBarrelOutcomeEnum::WinMax, null, $payout, $stake, null);
    }

    public static function lose(Character $character, int $stake, int $ratIndex): self
    {
        return new self(true, null, $character, LuckyBarrelOutcomeEnum::Lose, null, null, $stake, $ratIndex);
    }

    public static function forfeit(Character $character): self
    {
        return new self(true, null, $character, LuckyBarrelOutcomeEnum::Forfeit, null, null, null, null);
    }
}
