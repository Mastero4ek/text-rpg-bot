<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\StanceEnum;

final readonly class Fighter
{
    public function __construct(
        public string $name,
        public int $strength,
        public int $agility,
        public int $instinct,
        public int $vitality,
        public int $weaponDamage,
        public Mf $weaponMf,
        public StanceEnum $stance,
    ) {}
}
