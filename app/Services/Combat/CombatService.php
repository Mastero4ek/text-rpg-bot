<?php

declare(strict_types=1);

namespace App\Services\Combat;

use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Models\Character;
use App\Services\Game\GameConfig;
use App\Support\Game\Enemy;
use App\Support\Game\Fighter;
use App\Support\Game\HitResult;
use App\Support\Game\ItemDef;
use App\Support\Game\Mf;
use App\Support\Random\RandomSourceContract;
use RuntimeException;

final class CombatService
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly RandomSourceContract $random,
    ) {}

    /**
     * @return list<ZoneEnum>
     */
    public function zones(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('zones', $combat) || ! is_array($combat['zones'])) {
            throw new RuntimeException('combat.zones missing.');
        }

        $zones = [];

        foreach ($combat['zones'] as $zone) {
            if (! is_string($zone)) {
                throw new RuntimeException('Invalid combat zone.');
            }

            $zones[] = ZoneEnum::from($zone);
        }

        return $zones;
    }

    public function clamp(int $min, int $max, int $value): int
    {
        return max($min, min($max, $value));
    }

    public function clampFloat(float $min, float $max, float $value): float
    {
        return max($min, min($max, $value));
    }

    public function zoneRu(ZoneEnum $zone): string
    {
        return __('combat.zone_acc.' . $zone->value);
    }

    public function zoneLabel(ZoneEnum $zone): string
    {
        return __('combat.zone_label.' . $zone->value);
    }

    public function randomZone(): ZoneEnum
    {
        $zones = $this->zones();
        $index = (int) floor($this->random->float() * count($zones));

        if ($index >= count($zones)) {
            $index = count($zones) - 1;
        }

        return $zones[$index];
    }

    public function randomStance(): StanceEnum
    {
        $chance = $this->floatField($this->config->combat(), 'aiDefendChance');

        if ($this->random->float() < $chance) {
            return StanceEnum::DEFEND;
        }

        return StanceEnum::ATTACK;
    }

    public function calculateHit(
        Fighter $attacker,
        Fighter $defender,
        ZoneEnum $atkZone,
        ZoneEnum $defZone,
    ): HitResult {
        $atkMf = $this->applyStanceToMf(
            $this->baseMf($attacker)->merge($attacker->weaponMf),
            $attacker->stance,
        );
        $defMf = $this->applyStanceToMf(
            $this->baseMf($defender)->merge($defender->weaponMf),
            $defender->stance,
        );

        $blocked = $atkZone === $defZone;
        $zone = $this->zoneRu($atkZone);
        $pierce = $this->pierceConfig();
        $dodge = $this->dodgeConfig();
        $dmgCfg = $this->damageConfig();

        if ($blocked) {
            $pierceChance = $this->clampFloat(
                (float) $pierce['chanceMin'],
                (float) $pierce['chanceMax'],
                $pierce['chanceBase'] + ($atkMf['crit'] - $defMf['antiCrit']) * $pierce['chanceScale'],
            );

            if ($this->random->float() * 100 < $pierceChance) {
                $base = $this->calcBaseDamage($attacker, $atkMf['damageMult']);
                $mult = $pierce['multMin'] + $this->random->float() * $pierce['multRange'];
                $dmg = max(1, (int) floor($base * $mult));

                return new HitResult(
                    $dmg,
                    true,
                    true,
                    false,
                    __('combat.pierce', [
                        'attacker' => $attacker->name,
                        'zone' => $zone,
                        'dmg' => $dmg,
                    ]),
                );
            }

            return new HitResult(
                0,
                true,
                false,
                false,
                __('combat.block', [
                    'defender' => $defender->name,
                    'zone' => $zone,
                ]),
            );
        }

        $dodgeChance = $this->clampFloat(
            (float) $dodge['chanceMin'],
            (float) $dodge['chanceMax'],
            $dodge['chanceBase'] + ($defMf['dodge'] - $atkMf['antiDodge']) * $dodge['chanceScale'],
        );

        if ($this->random->float() * 100 < $dodgeChance) {
            return new HitResult(
                0,
                false,
                false,
                true,
                __('combat.dodge', [
                    'defender' => $defender->name,
                    'zone' => $zone,
                ]),
            );
        }

        $base = $this->calcBaseDamage($attacker, $atkMf['damageMult']);
        $dmg = max(
            1,
            (int) floor(
                $base * ($dmgCfg['varianceMin'] + $this->random->float() * $dmgCfg['varianceRange'])
            ),
        );

        return new HitResult(
            $dmg,
            false,
            false,
            false,
            __('combat.hit', [
                'attacker' => $attacker->name,
                'zone' => $zone,
                'dmg' => $dmg,
            ]),
        );
    }

    public function makeWoodenSoldier(): Enemy
    {
        $enemies = $this->config->enemies();

        if (! array_key_exists('woodenSoldier', $enemies) || ! is_array($enemies['woodenSoldier'])) {
            throw new RuntimeException('enemies.woodenSoldier missing.');
        }

        $e = $enemies['woodenSoldier'];

        if (! array_key_exists('weaponMf', $e) || ! is_array($e['weaponMf'])) {
            throw new RuntimeException('woodenSoldier.weaponMf missing.');
        }

        if (! array_key_exists('stance', $e) || ! is_string($e['stance'])) {
            throw new RuntimeException('woodenSoldier.stance missing.');
        }

        $maxHp = $this->intField($e, 'maxHp');

        return new Enemy(
            __('combat.enemy_soldier'),
            $this->intField($e, 'level'),
            $this->intField($e, 'strength'),
            $this->intField($e, 'agility'),
            $this->intField($e, 'instinct'),
            $this->intField($e, 'vitality'),
            $maxHp,
            $maxHp,
            $this->intField($e, 'weaponDamage'),
            Mf::fromArray($e['weaponMf']),
            StanceEnum::from($e['stance']),
        );
    }

    public function makeMob(int $level): Enemy
    {
        $enemies = $this->config->enemies();

        if (! array_key_exists('wanderer', $enemies) || ! is_array($enemies['wanderer'])) {
            throw new RuntimeException('enemies.wanderer missing.');
        }

        $w = $enemies['wanderer'];

        if (! array_key_exists('weaponMf', $w) || ! is_array($w['weaponMf'])) {
            throw new RuntimeException('wanderer.weaponMf missing.');
        }

        if (! array_key_exists('stance', $w) || ! is_string($w['stance'])) {
            throw new RuntimeException('wanderer.stance missing.');
        }

        $s = $this->intField($w, 'statBase') + $level * $this->intField($w, 'statPerLevel');
        $maxHp = $this->intField($w, 'hpBase') + $s * $this->intField($w, 'hpPerStat');

        return new Enemy(
            __('combat.enemy_wanderer', ['level' => $level]),
            $level,
            $s,
            $s,
            $s,
            $s,
            $maxHp,
            $maxHp,
            $level * $this->intField($w, 'weaponDamagePerLevel'),
            Mf::fromArray($w['weaponMf']),
            StanceEnum::from($w['stance']),
        );
    }

    /**
     * @return array{exp: int, gold: int}
     */
    public function pveRewards(int $enemyLevel): array
    {
        $lvl = max(0, $enemyLevel);
        $r = $this->pveRewardsConfig();
        $goldSpan = $r['goldMax'] - $r['goldMin'] + 1;

        return [
            'exp' => $r['expBase'] + $lvl * $r['expPerLevel'],
            'gold' => $r['goldMin'] + (int) floor($this->random->float() * $goldSpan),
        ];
    }

    public function potionHeal(): int
    {
        return $this->intField($this->config->combat(), 'potionHeal');
    }

    public function fighterFromPlayer(Character $character, ?ItemDef $weaponDef, string $name): Fighter
    {
        if (! $weaponDef instanceof ItemDef) {
            $weaponDamage = 0;
            $weaponMf = new Mf(0, 0, 0, 0);
        } else {
            $weaponDamage = $weaponDef->weaponDamage;
            $weaponMf = $weaponDef->mf;
        }

        return new Fighter(
            $name,
            $character->strength,
            $character->agility,
            $character->instinct,
            $character->vitality,
            $weaponDamage,
            $weaponMf,
            StanceEnum::DEFEND,
        );
    }

    public function fighterFromPlayerDefaultName(Character $character, ?ItemDef $weaponDef): Fighter
    {
        if ($character->username === null) {
            $name = __('common.you');
        } else {
            $name = $character->username;
        }

        return $this->fighterFromPlayer($character, $weaponDef, $name);
    }

    private function baseMf(Fighter $fighter): Mf
    {
        $k = $this->intField($this->config->combat(), 'mfPerStat');

        return new Mf(
            $fighter->agility * $k,
            $fighter->agility * $k,
            $fighter->instinct * $k,
            $fighter->instinct * $k,
        );
    }

    /**
     * @return array{dodge: int, antiDodge: int, crit: int, antiCrit: int, damageMult: float}
     */
    private function applyStanceToMf(Mf $mf, StanceEnum $stance): array
    {
        $s = $this->stanceConfig($stance);

        return [
            'dodge' => $mf->dodge + $s['dodge'],
            'antiDodge' => $mf->antiDodge + $s['antiDodge'],
            'crit' => $mf->crit + $s['crit'],
            'antiCrit' => $mf->antiCrit + $s['antiCrit'],
            'damageMult' => $s['damageMult'],
        ];
    }

    private function calcBaseDamage(Fighter $attacker, float $damageMult): float
    {
        $dmg = $this->damageConfig();
        $raw = $dmg['base'] + ($attacker->strength + $attacker->weaponDamage) * $dmg['statMultiplier'];

        return $raw * $damageMult;
    }

    /**
     * @return array{dodge: int, antiDodge: int, crit: int, antiCrit: int, damageMult: float}
     */
    private function stanceConfig(StanceEnum $stance): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('stances', $combat) || ! is_array($combat['stances'])) {
            throw new RuntimeException('combat.stances missing.');
        }

        if (! array_key_exists($stance->value, $combat['stances']) || ! is_array($combat['stances'][$stance->value])) {
            throw new RuntimeException('Unknown stance config.');
        }

        $s = $combat['stances'][$stance->value];

        return [
            'dodge' => $this->intField($s, 'dodge'),
            'antiDodge' => $this->intField($s, 'antiDodge'),
            'crit' => $this->intField($s, 'crit'),
            'antiCrit' => $this->intField($s, 'antiCrit'),
            'damageMult' => $this->floatField($s, 'damageMult'),
        ];
    }

    /**
     * @return array{
     *     chanceMin: int,
     *     chanceMax: int,
     *     chanceBase: int,
     *     chanceScale: float,
     *     multMin: float,
     *     multRange: float
     * }
     */
    private function pierceConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('pierce', $combat) || ! is_array($combat['pierce'])) {
            throw new RuntimeException('combat.pierce missing.');
        }

        $p = $combat['pierce'];

        return [
            'chanceMin' => $this->intField($p, 'chanceMin'),
            'chanceMax' => $this->intField($p, 'chanceMax'),
            'chanceBase' => $this->intField($p, 'chanceBase'),
            'chanceScale' => $this->floatField($p, 'chanceScale'),
            'multMin' => $this->floatField($p, 'multMin'),
            'multRange' => $this->floatField($p, 'multRange'),
        ];
    }

    /**
     * @return array{chanceMin: int, chanceMax: int, chanceBase: int, chanceScale: float}
     */
    private function dodgeConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('dodge', $combat) || ! is_array($combat['dodge'])) {
            throw new RuntimeException('combat.dodge missing.');
        }

        $d = $combat['dodge'];

        return [
            'chanceMin' => $this->intField($d, 'chanceMin'),
            'chanceMax' => $this->intField($d, 'chanceMax'),
            'chanceBase' => $this->intField($d, 'chanceBase'),
            'chanceScale' => $this->floatField($d, 'chanceScale'),
        ];
    }

    /**
     * @return array{base: int, statMultiplier: int, varianceMin: float, varianceRange: float}
     */
    private function damageConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('damage', $combat) || ! is_array($combat['damage'])) {
            throw new RuntimeException('combat.damage missing.');
        }

        $d = $combat['damage'];

        return [
            'base' => $this->intField($d, 'base'),
            'statMultiplier' => $this->intField($d, 'statMultiplier'),
            'varianceMin' => $this->floatField($d, 'varianceMin'),
            'varianceRange' => $this->floatField($d, 'varianceRange'),
        ];
    }

    /**
     * @return array{expBase: int, expPerLevel: int, goldMin: int, goldMax: int}
     */
    private function pveRewardsConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('pveRewards', $combat) || ! is_array($combat['pveRewards'])) {
            throw new RuntimeException('combat.pveRewards missing.');
        }

        $r = $combat['pveRewards'];

        return [
            'expBase' => $this->intField($r, 'expBase'),
            'expPerLevel' => $this->intField($r, 'expPerLevel'),
            'goldMin' => $this->intField($r, 'goldMin'),
            'goldMax' => $this->intField($r, 'goldMax'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        if (! array_key_exists($key, $row)) {
            throw new RuntimeException("Expected int {$key}.");
        }

        if (is_int($row[$key])) {
            return $row[$key];
        }

        if (is_float($row[$key]) && $row[$key] === (float) (int) $row[$key]) {
            return (int) $row[$key];
        }

        throw new RuntimeException("Expected int {$key}.");
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function floatField(array $row, string $key): float
    {
        if (! array_key_exists($key, $row)) {
            throw new RuntimeException("Expected float {$key}.");
        }

        if (is_int($row[$key]) || is_float($row[$key])) {
            return (float) $row[$key];
        }

        throw new RuntimeException("Expected float {$key}.");
    }
}
