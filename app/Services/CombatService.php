<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Models\Character;
use App\Services\Bag\BagCatalog;
use App\Support\Combat\Fighter;
use App\Support\Combat\HitResult;
use App\Support\Enemy;
use App\Support\Equipment\EquippedLoadout;
use App\Support\Mf;
use App\Support\Random\RandomSourceContract;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class CombatService
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly RandomSourceContract $random,
        private readonly BagCatalog $bagCatalog,
    ) {}

    /**
     * @return list<ZoneEnum>
     */
    public function zones(): array
    {
        return ZoneEnum::cases();
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

    /**
     * @param  list<ZoneEnum>  $defendZones
     */
    public function calculateHit(
        Fighter $attacker,
        Fighter $defender,
        ZoneEnum $atkZone,
        array $defendZones,
    ): HitResult {
        $atkMf = $this->scaleMfByStamina(
            $this->applyStanceToMf(
                $this->baseMf($attacker)->merge($attacker->weaponMf),
                $attacker->stance,
            ),
            $attacker->stamina,
            $attacker->maxStamina,
        );
        $defMf = $this->scaleMfByStamina(
            $this->applyStanceToMf(
                $this->baseMf($defender)->merge($defender->weaponMf),
                $defender->stance,
            ),
            $defender->stamina,
            $defender->maxStamina,
        );

        $blocked = in_array($atkZone, $defendZones, true);
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
                $raw = max(1, (int) floor($base * $mult));
                $dmg = $this->applyZoneArmor($raw, $defender, $atkZone);

                return new HitResult(
                    $dmg,
                    true,
                    true,
                    false,
                    false,
                    __('combat.pierce', [
                        'attacker' => TelegramHtml::escape($attacker->name),
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
                false,
                __('combat.block', [
                    'defender' => TelegramHtml::escape($defender->name),
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
                false,
                true,
                __('combat.dodge', [
                    'defender' => TelegramHtml::escape($defender->name),
                    'zone' => $zone,
                ]),
            );
        }

        $crit = $this->critConfig();
        $critChance = $this->clampFloat(
            (float) $crit['chanceMin'],
            (float) $crit['chanceMax'],
            $crit['chanceBase'] + ($atkMf['crit'] - $defMf['antiCrit']) * $crit['chanceScale'],
        );
        $base = $this->calcBaseDamage($attacker, $atkMf['damageMult']);

        if ($this->random->float() * 100 < $critChance) {
            $raw = max(1, (int) floor($base * $crit['mult']));
            $dmg = $this->applyZoneArmor($raw, $defender, $atkZone);

            return new HitResult(
                $dmg,
                false,
                false,
                true,
                false,
                __('combat.crit', [
                    'attacker' => TelegramHtml::escape($attacker->name),
                    'zone' => $zone,
                    'dmg' => $dmg,
                ]),
            );
        }

        $raw = max(
            1,
            (int) floor(
                $base * ($dmgCfg['varianceMin'] + $this->random->float() * $dmgCfg['varianceRange'])
            ),
        );
        $dmg = $this->applyZoneArmor($raw, $defender, $atkZone);

        return new HitResult(
            $dmg,
            false,
            false,
            false,
            false,
            __('combat.hit', [
                'attacker' => TelegramHtml::escape($attacker->name),
                'zone' => $zone,
                'dmg' => $dmg,
            ]),
        );
    }

    public function maxStamina(int $strength): int
    {
        $cfg = $this->staminaConfig();

        return max(0, $strength * $cfg['maxPerStrength']);
    }

    public function clampStamina(int $current, int $max): int
    {
        return max(0, min($max, $current));
    }

    public function staminaDrainForAttacker(StanceEnum $stance, bool $critical, bool $pierced): int
    {
        $cfg = $this->staminaConfig();

        if ($stance === StanceEnum::ATTACK) {
            $drain = $cfg['drainAttack'];
        } else {
            $drain = $cfg['drainDefend'];
        }

        if ($critical || $pierced) {
            $drain += $cfg['drainExtraOnCrit'];
        }

        return $drain;
    }

    public function staminaDrainForDefender(bool $dodged): int
    {
        $cfg = $this->staminaConfig();
        $drain = $cfg['drainDefend'];

        if ($dodged) {
            $drain += $cfg['drainExtraOnDodge'];
        }

        return $drain;
    }

    /**
     * @return array{exp: int, silver: int}
     */
    public function pveRewards(Character $character, Enemy $enemy): array
    {
        $r = $this->pveRewardsConfig();
        $expBase = $r['expBase'] + $character->level * $r['expPerLevel'];
        $silverSpan = $enemy->rewardSilverMax - $enemy->rewardSilverMin + 1;

        return [
            'exp' => (int) floor($expBase * $enemy->rewardExpPct / 100),
            'silver' => $enemy->rewardSilverMin + (int) floor($this->random->float() * $silverSpan),
        ];
    }

    public function potionHeal(): int
    {
        return $this->bagCatalog->potionHeal();
    }

    public function fighterFromPlayer(Character $character, EquippedLoadout $loadout, string $name): Fighter
    {
        $maxStamina = $this->maxStamina($character->strength);

        return new Fighter(
            $name,
            $character->strength,
            $character->agility,
            $character->instinct,
            $character->vitality,
            $this->rollWeaponDamage($loadout->mainHandDamageMin, $loadout->mainHandDamageMax),
            $loadout->mfForMainHandAttack(),
            StanceEnum::DEFEND,
            $loadout->armorByZone,
            $maxStamina,
            $maxStamina,
        );
    }

    public function rollWeaponDamage(int $min, int $max): int
    {
        if ($max < $min) {
            throw new RuntimeException('weapon damage max must be >= min.');
        }

        if ($min === $max) {
            return $min;
        }

        $span = $max - $min + 1;

        return $min + (int) floor($this->random->float() * $span);
    }

    public function fighterFromPlayerDefaultName(Character $character, EquippedLoadout $loadout): Fighter
    {
        if ($character->username === null) {
            $name = __('common.you');
        } else {
            $name = $character->username;
        }

        return $this->fighterFromPlayer($character, $loadout, $name);
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

    /**
     * @param  array{dodge: int, antiDodge: int, crit: int, antiCrit: int, damageMult: float}  $mf
     * @return array{dodge: int, antiDodge: int, crit: int, antiCrit: int, damageMult: float}
     */
    private function scaleMfByStamina(array $mf, int $stamina, int $maxStamina): array
    {
        if ($maxStamina <= 0) {
            $scale = 0.0;
        } else {
            $scale = $stamina / $maxStamina;
        }

        return [
            'dodge' => (int) floor($mf['dodge'] * $scale),
            'antiDodge' => (int) floor($mf['antiDodge'] * $scale),
            'crit' => (int) floor($mf['crit'] * $scale),
            'antiCrit' => (int) floor($mf['antiCrit'] * $scale),
            'damageMult' => $mf['damageMult'],
        ];
    }

    /**
     * @return array{
     *     maxPerStrength: int,
     *     drainDefend: int,
     *     drainAttack: int,
     *     drainExtraOnCrit: int,
     *     drainExtraOnDodge: int
     * }
     */
    private function staminaConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('stamina', $combat) || ! is_array($combat['stamina'])) {
            throw new RuntimeException('settings.combat.stamina missing.');
        }

        $s = $combat['stamina'];

        return [
            'maxPerStrength' => $this->intField($s, 'maxPerStrength'),
            'drainDefend' => $this->intField($s, 'drainDefend'),
            'drainAttack' => $this->intField($s, 'drainAttack'),
            'drainExtraOnCrit' => $this->intField($s, 'drainExtraOnCrit'),
            'drainExtraOnDodge' => $this->intField($s, 'drainExtraOnDodge'),
        ];
    }

    private function applyZoneArmor(int $rawDamage, Fighter $defender, ZoneEnum $atkZone): int
    {
        return max(1, $rawDamage - $defender->armorForZone($atkZone));
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
            throw new RuntimeException('settings.combat.stances missing.');
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
     * @return array{chanceMin: int, chanceMax: int, chanceBase: int, chanceScale: float, mult: float}
     */
    private function critConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('crit', $combat) || ! is_array($combat['crit'])) {
            throw new RuntimeException('settings.combat.crit missing.');
        }

        $c = $combat['crit'];

        return [
            'chanceMin' => $this->intField($c, 'chanceMin'),
            'chanceMax' => $this->intField($c, 'chanceMax'),
            'chanceBase' => $this->intField($c, 'chanceBase'),
            'chanceScale' => $this->floatField($c, 'chanceScale'),
            'mult' => $this->floatField($c, 'mult'),
        ];
    }

    /**
     * @return array{chanceMin: int, chanceMax: int, chanceBase: int, chanceScale: float}
     */
    private function dodgeConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('dodge', $combat) || ! is_array($combat['dodge'])) {
            throw new RuntimeException('settings.combat.dodge missing.');
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
            throw new RuntimeException('settings.combat.pierce missing.');
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
     * @return array{base: int, statMultiplier: int, varianceMin: float, varianceRange: float}
     */
    private function damageConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('damage', $combat) || ! is_array($combat['damage'])) {
            throw new RuntimeException('settings.combat.damage missing.');
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
     * @return array{expBase: int, expPerLevel: int}
     */
    private function pveRewardsConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('pveRewards', $combat) || ! is_array($combat['pveRewards'])) {
            throw new RuntimeException('settings.combat.pveRewards missing.');
        }

        $r = $combat['pveRewards'];

        return [
            'expBase' => $this->intField($r, 'expBase'),
            'expPerLevel' => $this->intField($r, 'expPerLevel'),
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
