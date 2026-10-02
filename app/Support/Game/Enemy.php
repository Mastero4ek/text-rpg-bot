<?php

declare(strict_types=1);

namespace App\Support\Game;

use App\Enums\StanceEnum;
use InvalidArgumentException;

final readonly class Enemy
{
    public function __construct(
        public string $name,
        public int $level,
        public int $strength,
        public int $agility,
        public int $instinct,
        public int $vitality,
        public int $maxHp,
        public int $currentHp,
        public int $weaponDamage,
        public Mf $weaponMf,
        public StanceEnum $stance,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('name', $data) || ! is_string($data['name'])) {
            throw new InvalidArgumentException('Enemy name is required.');
        }

        if (! array_key_exists('weaponMf', $data) || ! is_array($data['weaponMf'])) {
            throw new InvalidArgumentException('Enemy weaponMf is required.');
        }

        if (! array_key_exists('stance', $data) || ! is_string($data['stance'])) {
            throw new InvalidArgumentException('Enemy stance is required.');
        }

        return new self(
            $data['name'],
            self::intField($data, 'level'),
            self::intField($data, 'strength'),
            self::intField($data, 'agility'),
            self::intField($data, 'instinct'),
            self::intField($data, 'vitality'),
            self::intField($data, 'maxHp'),
            self::intField($data, 'current_hp'),
            self::intField($data, 'weaponDamage'),
            Mf::fromArray($data['weaponMf']),
            StanceEnum::from($data['stance']),
        );
    }

    /**
     * @return array{
     *     name: string,
     *     level: int,
     *     strength: int,
     *     agility: int,
     *     instinct: int,
     *     vitality: int,
     *     maxHp: int,
     *     current_hp: int,
     *     weaponDamage: int,
     *     weaponMf: array{dodge: int, antiDodge: int, crit: int, antiCrit: int},
     *     stance: string
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'level' => $this->level,
            'strength' => $this->strength,
            'agility' => $this->agility,
            'instinct' => $this->instinct,
            'vitality' => $this->vitality,
            'maxHp' => $this->maxHp,
            'current_hp' => $this->currentHp,
            'weaponDamage' => $this->weaponDamage,
            'weaponMf' => $this->weaponMf->toArray(),
            'stance' => $this->stance->value,
        ];
    }

    public function toFighter(): Fighter
    {
        return new Fighter(
            $this->name,
            $this->strength,
            $this->agility,
            $this->instinct,
            $this->vitality,
            $this->weaponDamage,
            $this->weaponMf,
            $this->stance,
        );
    }

    public function withCurrentHp(int $currentHp): self
    {
        return new self(
            $this->name,
            $this->level,
            $this->strength,
            $this->agility,
            $this->instinct,
            $this->vitality,
            $this->maxHp,
            $currentHp,
            $this->weaponDamage,
            $this->weaponMf,
            $this->stance,
        );
    }

    public function withStance(StanceEnum $stance): self
    {
        return new self(
            $this->name,
            $this->level,
            $this->strength,
            $this->agility,
            $this->instinct,
            $this->vitality,
            $this->maxHp,
            $this->currentHp,
            $this->weaponDamage,
            $this->weaponMf,
            $stance,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function intField(array $data, string $key): int
    {
        if (! array_key_exists($key, $data) || ! is_int($data[$key])) {
            throw new InvalidArgumentException("Enemy {$key} is required.");
        }

        return $data[$key];
    }
}
