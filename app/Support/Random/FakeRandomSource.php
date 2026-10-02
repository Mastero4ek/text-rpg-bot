<?php

declare(strict_types=1);

namespace App\Support\Random;

use RuntimeException;

final class FakeRandomSource implements RandomSourceContract
{
    /** @var list<float> */
    private array $values;

    private int $index = 0;

    /**
     * @param  list<float>  $values
     */
    public function __construct(array $values)
    {
        if ($values === []) {
            throw new RuntimeException('FakeRandomSource needs at least one value.');
        }

        $this->values = $values;
    }

    public function float(): float
    {
        $lastIndex = count($this->values) - 1;

        if ($this->index >= $lastIndex) {
            return $this->values[$lastIndex];
        }

        $value = $this->values[$this->index];
        $this->index++;

        return $value;
    }
}
