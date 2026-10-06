<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Mf
{
    public function __construct(
        public int $dodge,
        public int $antiDodge,
        public int $crit,
        public int $antiCrit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $dodge = 0;
        if (array_key_exists('dodge', $data) && is_int($data['dodge'])) {
            $dodge = $data['dodge'];
        }

        $antiDodge = 0;
        if (array_key_exists('antiDodge', $data) && is_int($data['antiDodge'])) {
            $antiDodge = $data['antiDodge'];
        }

        $crit = 0;
        if (array_key_exists('crit', $data) && is_int($data['crit'])) {
            $crit = $data['crit'];
        }

        $antiCrit = 0;
        if (array_key_exists('antiCrit', $data) && is_int($data['antiCrit'])) {
            $antiCrit = $data['antiCrit'];
        }

        return new self($dodge, $antiDodge, $crit, $antiCrit);
    }

    public function merge(self $other): self
    {
        return new self(
            $this->dodge + $other->dodge,
            $this->antiDodge + $other->antiDodge,
            $this->crit + $other->crit,
            $this->antiCrit + $other->antiCrit,
        );
    }

    /**
     * @return array{dodge: int, antiDodge: int, crit: int, antiCrit: int}
     */
    public function toArray(): array
    {
        return [
            'dodge' => $this->dodge,
            'antiDodge' => $this->antiDodge,
            'crit' => $this->crit,
            'antiCrit' => $this->antiCrit,
        ];
    }
}
