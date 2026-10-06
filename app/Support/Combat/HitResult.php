<?php

declare(strict_types=1);

namespace App\Support\Combat;

final readonly class HitResult
{
    public function __construct(
        public int $dmg,
        public bool $blocked,
        public bool $pierced,
        public bool $critical,
        public bool $dodged,
        public string $logLine,
    ) {}
}
