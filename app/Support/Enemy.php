<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Combat\StanceEnum;
use App\Support\Combat\Fighter;
use InvalidArgumentException;

final readonly class Enemy
{
    /**
     * @param  array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}  $armorByZone
     * @param  list<array{bag_catalog_id: string, chance_pct: int}>  $drops
     */
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
        public int $maxStamina,
        public int $stamina,
        public array $armorByZone,
        public int $attackSlots,
        public int $blockSlots,
        public int $offHandWeaponDamage,
        public Mf $offHandWeaponMf,
        public string $catalogId,
        public int $rewardExpPct,
        public int $rewardSilverMin,
        public int $rewardSilverMax,
        public array $drops,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('name', $data) || ! is_string($data['name'])) {
            throw new InvalidArgumentException('Enemy name is required.');
        }

        if (! array_key_exists('catalogId', $data) || ! is_string($data['catalogId']) || $data['catalogId'] === '') {
            throw new InvalidArgumentException('Enemy catalogId is required.');
        }

        if (! array_key_exists('weaponMf', $data) || ! is_array($data['weaponMf'])) {
            throw new InvalidArgumentException('Enemy weaponMf is required.');
        }

        if (! array_key_exists('offHandWeaponMf', $data) || ! is_array($data['offHandWeaponMf'])) {
            throw new InvalidArgumentException('Enemy offHandWeaponMf is required.');
        }

        if (! array_key_exists('stance', $data) || ! is_string($data['stance'])) {
            throw new InvalidArgumentException('Enemy stance is required.');
        }

        if (! array_key_exists('armorByZone', $data) || ! is_array($data['armorByZone'])) {
            throw new InvalidArgumentException('Enemy armorByZone is required.');
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
            self::intField($data, 'maxStamina'),
            self::intField($data, 'stamina'),
            self::armorByZone($data['armorByZone']),
            self::intField($data, 'attackSlots'),
            self::intField($data, 'blockSlots'),
            self::intField($data, 'offHandWeaponDamage'),
            Mf::fromArray($data['offHandWeaponMf']),
            $data['catalogId'],
            self::intField($data, 'rewardExpPct'),
            self::intField($data, 'rewardSilverMin'),
            self::intField($data, 'rewardSilverMax'),
            self::drops($data),
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
     *     stance: string,
     *     maxStamina: int,
     *     stamina: int,
     *     armorByZone: array{HEAD: int, CHEST: int, BELLY: int, LEGS: int},
     *     attackSlots: int,
     *     blockSlots: int,
     *     offHandWeaponDamage: int,
     *     offHandWeaponMf: array{dodge: int, antiDodge: int, crit: int, antiCrit: int},
     *     catalogId: string,
     *     rewardExpPct: int,
     *     rewardSilverMin: int,
     *     rewardSilverMax: int,
     *     drops: list<array{bag_catalog_id: string, chance_pct: int}>
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
            'maxStamina' => $this->maxStamina,
            'stamina' => $this->stamina,
            'armorByZone' => $this->armorByZone,
            'attackSlots' => $this->attackSlots,
            'blockSlots' => $this->blockSlots,
            'offHandWeaponDamage' => $this->offHandWeaponDamage,
            'offHandWeaponMf' => $this->offHandWeaponMf->toArray(),
            'catalogId' => $this->catalogId,
            'rewardExpPct' => $this->rewardExpPct,
            'rewardSilverMin' => $this->rewardSilverMin,
            'rewardSilverMax' => $this->rewardSilverMax,
            'drops' => $this->drops,
        ];
    }

    public function toFighter(): Fighter
    {
        return $this->fighterWithWeapon($this->weaponDamage, $this->weaponMf);
    }

    public function toOffHandFighter(): Fighter
    {
        return $this->fighterWithWeapon($this->offHandWeaponDamage, $this->offHandWeaponMf);
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
            $this->maxStamina,
            $this->stamina,
            $this->armorByZone,
            $this->attackSlots,
            $this->blockSlots,
            $this->offHandWeaponDamage,
            $this->offHandWeaponMf,
            $this->catalogId,
            $this->rewardExpPct,
            $this->rewardSilverMin,
            $this->rewardSilverMax,
            $this->drops,
        );
    }

    public function withStamina(int $stamina): self
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
            $this->stance,
            $this->maxStamina,
            $stamina,
            $this->armorByZone,
            $this->attackSlots,
            $this->blockSlots,
            $this->offHandWeaponDamage,
            $this->offHandWeaponMf,
            $this->catalogId,
            $this->rewardExpPct,
            $this->rewardSilverMin,
            $this->rewardSilverMax,
            $this->drops,
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
            $this->maxStamina,
            $this->stamina,
            $this->armorByZone,
            $this->attackSlots,
            $this->blockSlots,
            $this->offHandWeaponDamage,
            $this->offHandWeaponMf,
            $this->catalogId,
            $this->rewardExpPct,
            $this->rewardSilverMin,
            $this->rewardSilverMax,
            $this->drops,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}
     */
    private static function armorByZone(array $data): array
    {
        return [
            'HEAD' => self::intField($data, 'HEAD'),
            'CHEST' => self::intField($data, 'CHEST'),
            'BELLY' => self::intField($data, 'BELLY'),
            'LEGS' => self::intField($data, 'LEGS'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{bag_catalog_id: string, chance_pct: int}>
     */
    private static function drops(array $data): array
    {
        if (! array_key_exists('drops', $data) || ! is_array($data['drops'])) {
            throw new InvalidArgumentException('Enemy drops is required.');
        }

        $drops = [];

        foreach ($data['drops'] as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Enemy drop row is required.');
            }

            if (! array_key_exists('bag_catalog_id', $row) || ! is_string($row['bag_catalog_id']) || $row['bag_catalog_id'] === '') {
                throw new InvalidArgumentException('Enemy drop bag_catalog_id is required.');
            }

            $drops[] = [
                'bag_catalog_id' => $row['bag_catalog_id'],
                'chance_pct' => self::intField($row, 'chance_pct'),
            ];
        }

        return $drops;
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

    private function fighterWithWeapon(int $weaponDamage, Mf $weaponMf): Fighter
    {
        return new Fighter(
            $this->name,
            $this->strength,
            $this->agility,
            $this->instinct,
            $this->vitality,
            $weaponDamage,
            $weaponMf,
            $this->stance,
            $this->armorByZone,
            $this->stamina,
            $this->maxStamina,
        );
    }
}
