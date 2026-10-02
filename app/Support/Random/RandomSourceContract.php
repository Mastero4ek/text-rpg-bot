<?php

declare(strict_types=1);

namespace App\Support\Random;

interface RandomSourceContract
{
    /** Float in [0, 1), like Math.random(). */
    public function float(): float;
}
