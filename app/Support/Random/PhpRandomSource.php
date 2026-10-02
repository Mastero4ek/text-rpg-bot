<?php

declare(strict_types=1);

namespace App\Support\Random;

final class PhpRandomSource implements RandomSourceContract
{
    public function float(): float
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }
}
