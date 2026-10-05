<?php

declare(strict_types=1);

namespace App\Services\Character;

use App\Enums\Equipment\TypeEnum;
use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Game\GameConfig;
use App\Services\Inventory\LoadoutService;
use App\Support\Game\ActionResult;
use App\Support\Game\Mf;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CharacterService
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly LoadoutService $loadout,
    ) {}

    public function clampHp(int $hp, int $maxHp): int
    {
        return max(0, min($maxHp, $hp));
    }

    public function clampStamina(int $stamina, int $maxStamina): int
    {
        return max(0, min($maxStamina, $stamina));
    }

    public function baseMaxHp(int $vitality): int
    {
        $maxHp = $this->characterMaxHpConfig();

        return $vitality * $maxHp['perVitality'];
    }

    public function armorBonus(Character $character): int
    {
        return $this->loadout->forCharacter($character)->statBonus;
    }

    public function maxHp(Character $character): int
    {
        return $character->max_hp + $this->armorBonus($character);
    }

    public function bodyStamina(Character $character): int
    {
        return $character->max_stamina;
    }

    public function maxStamina(Character $character): int
    {
        return $character->max_stamina;
    }

    public function maxStaminaFromStrength(int $strength): int
    {
        $stamina = $this->combatStaminaConfig();

        return $strength * $stamina['maxPerStrength'];
    }

    public function bodyMf(Character $character): Mf
    {
        $k = $this->mfPerStat();

        return new Mf(
            $character->agility * $k,
            $character->agility * $k,
            $character->instinct * $k,
            $character->instinct * $k,
        );
    }

    public function nextExpThreshold(Character $character): ?int
    {
        $levelCfg = $this->characterLevelConfig();

        if ($character->level >= $levelCfg['max']) {
            return null;
        }

        foreach ($this->experienceRows() as $row) {
            if ($row['kind'] === 'start') {
                continue;
            }

            if ($row['exp'] > $character->exp) {
                return $row['exp'];
            }
        }

        return null;
    }

    public function findByTgId(int $tgId): Character
    {
        $character = Character::query()->find($tgId);

        if ($character === null) {
            throw (new ModelNotFoundException)->setModel(Character::class, [$tgId]);
        }

        return $character;
    }

    public function createDraft(int $tgId): Character
    {
        return DB::transaction(function () use ($tgId): Character {
            $start = $this->onboardingStartConfig();
            $hp = $this->baseMaxHp($start['vitality']);
            $stamina = $this->maxStaminaFromStrength($start['strength']);

            $character = new Character;
            $character->tg_id = $tgId;
            $character->username = null;
            $character->location = null;
            $character->onboarding_step = OnboardingStepEnum::NICK;
            $character->level = $start['level'];
            $character->exp = $start['exp'];
            $character->silver = $start['silver'];
            $character->gold = $start['gold'];
            $character->strength = $start['strength'];
            $character->agility = $start['agility'];
            $character->instinct = $start['instinct'];
            $character->vitality = $start['vitality'];
            $character->current_hp = $hp;
            $character->max_hp = $hp;
            $character->last_hp_update = now();
            $character->current_stamina = $stamina;
            $character->max_stamina = $stamina;
            $character->last_stamina_update = now();
            $character->stat_points = $start['statPoints'];
            $character->bag_max_rows = $this->defaultBagMaxRows();
            $character->inventory_max_rows = $this->defaultInventoryMaxRows();
            $character->arena_points = 0;
            $character->premium_until = null;
            $character->save();

            return $character;
        });
    }

    public function setBagMaxRows(Character $character, int $maxRows): Character
    {
        if ($maxRows < 1) {
            throw new InvalidArgumentException('Bag max rows must be >= 1.');
        }

        return DB::transaction(function () use ($character, $maxRows): Character {
            $locked = Character::query()
                ->whereKey($character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new ModelNotFoundException('Character not found.');
            }

            $locked->bag_max_rows = $maxRows;
            $locked->save();

            return $locked;
        });
    }

    public function setInventoryMaxRows(Character $character, int $maxRows): Character
    {
        if ($maxRows < 1) {
            throw new InvalidArgumentException('Inventory max rows must be >= 1.');
        }

        return DB::transaction(function () use ($character, $maxRows): Character {
            $locked = Character::query()
                ->whereKey($character->tg_id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new ModelNotFoundException('Character not found.');
            }

            $locked->inventory_max_rows = $maxRows;
            $locked->save();

            return $locked;
        });
    }

    public function applyRegen(Character $character): Character
    {
        return DB::transaction(function () use ($character): Character {
            $dirty = false;
            $hpCap = $this->maxHp($character);
            $regen = $this->characterRegenConfig();

            if ($character->current_hp < $hpCap) {
                $secondsPassed = (int) $character->last_hp_update->diffInSeconds(now());
                $hpPerTick = (int) floor($character->vitality / $regen['vitalityDivisor']) + $regen['vitalityBonus'];
                $ticks = (int) floor($secondsPassed / $regen['tickSeconds']);
                $hpToRegen = $ticks * $hpPerTick;

                if ($hpToRegen > 0) {
                    $character->current_hp = min($hpCap, $character->current_hp + $hpToRegen);
                    $character->last_hp_update = now();
                    $dirty = true;
                }
            } else {
                $character->last_hp_update = now();
                $dirty = true;
            }

            $staminaCap = $this->maxStamina($character);
            $staminaRegen = $this->characterStaminaRegenConfig();

            if ($character->current_stamina < $staminaCap) {
                $secondsPassed = (int) $character->last_stamina_update->diffInSeconds(now());
                $staminaPerTick = (int) floor($character->strength / $staminaRegen['strengthDivisor']) + $staminaRegen['strengthBonus'];
                $ticks = (int) floor($secondsPassed / $staminaRegen['tickSeconds']);
                $staminaToRegen = $ticks * $staminaPerTick;

                if ($staminaToRegen > 0) {
                    $character->current_stamina = min(
                        $staminaCap,
                        $character->current_stamina + $staminaToRegen,
                    );
                    $character->last_stamina_update = now();
                    $dirty = true;
                }
            } else {
                $character->last_stamina_update = now();
                $dirty = true;
            }

            if ($dirty) {
                $character->save();
            }

            return $character;
        });
    }

    public function hasActivePremium(Character $character): bool
    {
        if (! $character->premium_until instanceof CarbonInterface) {
            return false;
        }

        return $character->premium_until->isFuture();
    }

    public function getFresh(int $tgId): Character
    {
        $character = Character::query()->find($tgId);

        if ($character === null) {
            throw (new ModelNotFoundException)->setModel(Character::class, [$tgId]);
        }

        return $this->applyRegen($character);
    }

    public function applyExperienceThresholds(Character $character, int $oldExp, int $newExp): bool
    {
        $levelCfg = $this->characterLevelConfig();

        if ($character->level >= $levelCfg['max']) {
            return false;
        }

        $applied = false;

        foreach ($this->experienceRows() as $row) {
            if ($row['kind'] === 'start') {
                continue;
            }

            if ($row['exp'] <= $oldExp) {
                continue;
            }

            if ($row['exp'] > $newExp) {
                continue;
            }

            if ($character->level >= $levelCfg['max']) {
                break;
            }

            if ($row['kind'] === 'up') {
                $character->stat_points += $levelCfg['statPointsOnUp'];
                $character->silver += $row['silverGain'];
                $applied = true;

                continue;
            }

            if ($row['kind'] === 'level') {
                $character->level = $row['level'];
                $character->stat_points += $this->statPointsForLevel($row['level']);
                $character->silver += $row['silverGain'];
                $applied = true;
            }
        }

        return $applied;
    }

    public function addExpSilver(Character $character, int $expGain, int $silverGain): Character
    {
        return DB::transaction(function () use ($character, $expGain, $silverGain): Character {
            $oldExp = $character->exp;
            $character->exp += $expGain;
            $character->silver += $silverGain;
            $this->applyExperienceThresholds($character, $oldExp, $character->exp);
            $character->save();

            return $character;
        });
    }

    public function grantExp(Character $character, int $amount): Character
    {
        return DB::transaction(function () use ($character, $amount): Character {
            $oldExp = $character->exp;
            $character->exp += $amount;
            $this->applyExperienceThresholds($character, $oldExp, $character->exp);
            $character->save();

            return $character;
        });
    }

    public function grantSilver(Character $character, int $amount): Character
    {
        return DB::transaction(function () use ($character, $amount): Character {
            $character->silver += $amount;
            $character->save();

            return $character;
        });
    }

    public function grantGold(Character $character, int $amount): Character
    {
        return DB::transaction(function () use ($character, $amount): Character {
            $character->gold += $amount;
            $character->save();

            return $character;
        });
    }

    public function grantStatPoints(Character $character, int $amount): Character
    {
        return DB::transaction(function () use ($character, $amount): Character {
            $character->stat_points += $amount;
            $character->save();

            return $character;
        });
    }

    public function spendStatPoint(Character $character, string $stat): ActionResult
    {
        return DB::transaction(function () use ($character, $stat): ActionResult {
            $statKey = StatKeyEnum::tryFrom($stat);

            if ($statKey === null) {
                return ActionResult::fail(__('errors.unknown_stat'));
            }

            if ($character->stat_points <= 0) {
                return ActionResult::fail(__('errors.no_stat_points'));
            }

            $column = $statKey->column();
            $character->{$column} += 1;
            $character->stat_points -= 1;

            if ($statKey === StatKeyEnum::VITALITY) {
                $gain = $this->characterMaxHpConfig()['perVitality'];
                $character->max_hp += $gain;
                $character->current_hp = $this->clampHp(
                    $character->current_hp + $gain,
                    $this->maxHp($character),
                );
            }

            if ($statKey === StatKeyEnum::STRENGTH) {
                $gain = $this->combatStaminaConfig()['maxPerStrength'];
                $character->max_stamina += $gain;
                $character->current_stamina = $this->clampStamina(
                    $character->current_stamina + $gain,
                    $this->maxStamina($character),
                );
            }

            $character->save();

            return ActionResult::ok($character);
        });
    }

    public function resetStats(Character $character): Character
    {
        return DB::transaction(function () use ($character): Character {
            $start = $this->onboardingStartConfig();
            $character->strength = $start['strength'];
            $character->agility = $start['agility'];
            $character->instinct = $start['instinct'];
            $character->vitality = $start['vitality'];
            $character->stat_points = $this->totalEarnedStatPoints($character);
            $character->max_hp = $this->baseMaxHp($character->vitality);
            $character->max_stamina = $this->maxStaminaFromStrength($character->strength);
            $cap = $this->maxHp($character);
            $character->current_hp = $this->clampHp($character->current_hp, $cap);
            $staminaCap = $this->maxStamina($character);
            $character->current_stamina = $this->clampStamina($character->current_stamina, $staminaCap);
            $character->save();

            return $character;
        });
    }

    public function resetStatsForGold(Character $character): ActionResult
    {
        return DB::transaction(function () use ($character): ActionResult {
            $cost = $this->statResetGoldCost();

            if ($character->gold < $cost) {
                return ActionResult::fail(__('errors.not_enough_gold'));
            }

            $character->gold -= $cost;
            $this->resetStats($character);

            return ActionResult::ok($character->fresh());
        });
    }

    public function totalEarnedStatPoints(Character $character): int
    {
        $start = $this->onboardingStartConfig();
        $levelCfg = $this->characterLevelConfig();
        $total = $start['statPoints'];

        foreach ($this->experienceRows() as $row) {
            if ($row['exp'] > $character->exp) {
                continue;
            }

            if ($row['kind'] === 'up') {
                $total += $levelCfg['statPointsOnUp'];

                continue;
            }

            if ($row['kind'] === 'level') {
                $total += $this->statPointsForLevel($row['level']);
            }
        }

        return $total;
    }

    public function profileText(Character $character): string
    {
        $cap = $this->maxHp($character);
        $next = $this->nextExpThreshold($character);

        if ($next === null) {
            $need = '—';
        } else {
            $need = $character->exp . '/' . $next;
        }

        if ($character->username === null) {
            $name = __('common.unnamed');
        } else {
            $name = $character->username;
        }

        if ($character->location === null) {
            $city = '—';
        } else {
            $city = $character->location;
        }

        $lines = [
            __('profile.card', [
                'name' => $name,
                'city' => $city,
                'level' => $character->level,
                'hp' => $character->current_hp,
                'maxHp' => $cap,
                'silver' => $character->silver,
                'gold' => $character->gold,
                'stamina' => $character->current_stamina,
                'maxStamina' => $this->maxStamina($character),
                'potions' => (int) Inventory::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('item_type', TypeEnum::POTION)
                    ->sum('quantity'),
                'exp' => $need,
                'str' => $character->strength,
                'agi' => $character->agility,
                'inst' => $character->instinct,
                'vit' => $character->vitality,
            ]),
        ];

        if ($character->stat_points > 0) {
            $lines[] = __('profile.free_points', ['points' => $character->stat_points]);
        }

        $lines[] = '';
        $lines[] = __('profile.gear_heading');
        $lines[] = $this->loadout->gearText($this->loadout->forCharacter($character));

        return implode("\n", $lines);
    }

    public function statsScreenText(Character $character): string
    {
        $bodyMf = $this->bodyMf($character);
        $bodyHp = $this->baseMaxHp($character->vitality);
        $bodyStamina = $this->bodyStamina($character);
        $loadout = $this->loadout->forCharacter($character);
        $totalMf = $bodyMf->merge($loadout->mf);
        $totalHp = $this->maxHp($character);

        return __('profile.stats_screen', [
            'points' => $character->stat_points,
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
            'bodyStamina' => $bodyStamina,
            'bodyHp' => $bodyHp,
            'bodyDodge' => $bodyMf->dodge,
            'bodyAntiDodge' => $bodyMf->antiDodge,
            'bodyCrit' => $bodyMf->crit,
            'bodyAntiCrit' => $bodyMf->antiCrit,
            'totalStamina' => $bodyStamina,
            'totalHp' => $totalHp,
            'totalDodge' => $totalMf->dodge,
            'totalAntiDodge' => $totalMf->antiDodge,
            'totalCrit' => $totalMf->crit,
            'totalAntiCrit' => $totalMf->antiCrit,
            'gearHp' => $loadout->statBonus,
        ]);
    }

    public function usernameTakenByOther(string $username, int $tgId): bool
    {
        return Character::query()
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
            ->where('tg_id', '!=', $tgId)
            ->exists();
    }

    public function statResetGoldCost(): int
    {
        $character = $this->config->character();

        if (! array_key_exists('statReset', $character) || ! is_array($character['statReset'])) {
            throw new RuntimeException('character.statReset missing.');
        }

        return $this->intField($character['statReset'], 'goldCost');
    }

    private function statPointsForLevel(int $newLevel): int
    {
        $levelCfg = $this->characterLevelConfig();

        if ($newLevel > $levelCfg['levelAboveThreshold']) {
            return $levelCfg['statPointsOnLevelAbove'];
        }

        return $levelCfg['statPointsOnLevel'];
    }

    private function mfPerStat(): int
    {
        return $this->intField($this->config->combat(), 'mfPerStat');
    }

    /**
     * @return array{maxPerStrength: int}
     */
    private function combatStaminaConfig(): array
    {
        $combat = $this->config->combat();

        if (! array_key_exists('stamina', $combat) || ! is_array($combat['stamina'])) {
            throw new RuntimeException('settings.combat.stamina missing.');
        }

        return [
            'maxPerStrength' => $this->intField($combat['stamina'], 'maxPerStrength'),
        ];
    }

    /**
     * @return array{perVitality: int}
     */
    private function characterMaxHpConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('maxHp', $character) || ! is_array($character['maxHp'])) {
            throw new RuntimeException('character.maxHp missing.');
        }

        return [
            'perVitality' => $this->intField($character['maxHp'], 'perVitality'),
        ];
    }

    /**
     * @return array{tickSeconds: int, vitalityDivisor: int, vitalityBonus: int}
     */
    private function characterRegenConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('regen', $character) || ! is_array($character['regen'])) {
            throw new RuntimeException('character.regen missing.');
        }

        return [
            'tickSeconds' => $this->intField($character['regen'], 'tickSeconds'),
            'vitalityDivisor' => $this->intField($character['regen'], 'vitalityDivisor'),
            'vitalityBonus' => $this->intField($character['regen'], 'vitalityBonus'),
        ];
    }

    /**
     * @return array{tickSeconds: int, strengthDivisor: int, strengthBonus: int}
     */
    private function characterStaminaRegenConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('staminaRegen', $character) || ! is_array($character['staminaRegen'])) {
            throw new RuntimeException('character.staminaRegen missing.');
        }

        return [
            'tickSeconds' => $this->intField($character['staminaRegen'], 'tickSeconds'),
            'strengthDivisor' => $this->intField($character['staminaRegen'], 'strengthDivisor'),
            'strengthBonus' => $this->intField($character['staminaRegen'], 'strengthBonus'),
        ];
    }

    /**
     * @return array{
     *     max: int,
     *     upsPerLevel: int,
     *     statPointsOnUp: int,
     *     statPointsOnLevel: int,
     *     statPointsOnLevelAbove: int,
     *     levelAboveThreshold: int
     * }
     */
    private function characterLevelConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('level', $character) || ! is_array($character['level'])) {
            throw new RuntimeException('character.level missing.');
        }

        return [
            'max' => $this->intField($character['level'], 'max'),
            'upsPerLevel' => $this->intField($character['level'], 'upsPerLevel'),
            'statPointsOnUp' => $this->intField($character['level'], 'statPointsOnUp'),
            'statPointsOnLevel' => $this->intField($character['level'], 'statPointsOnLevel'),
            'statPointsOnLevelAbove' => $this->intField($character['level'], 'statPointsOnLevelAbove'),
            'levelAboveThreshold' => $this->intField($character['level'], 'levelAboveThreshold'),
        ];
    }

    /**
     * @return list<array{level: int, exp: int, kind: string, silverGain: int}>
     */
    private function experienceRows(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('experience', $character) || ! is_array($character['experience'])) {
            throw new RuntimeException('character.experience missing.');
        }

        $rows = [];

        foreach ($character['experience'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('character.experience row invalid.');
            }

            if (! array_key_exists('kind', $row) || ! is_string($row['kind'])) {
                throw new RuntimeException('character.experience.kind missing.');
            }

            $rows[] = [
                'level' => $this->intField($row, 'level'),
                'exp' => $this->intField($row, 'exp'),
                'kind' => $row['kind'],
                'silverGain' => $this->intField($row, 'silverGain'),
            ];
        }

        return $rows;
    }

    private function defaultBagMaxRows(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('gems', $settings) || ! is_array($settings['gems'])) {
            throw new RuntimeException('settings.gems missing.');
        }

        $gems = $settings['gems'];

        if (! array_key_exists('bagMaxRows', $gems) || ! is_int($gems['bagMaxRows'])) {
            throw new RuntimeException('settings.gems.bagMaxRows missing.');
        }

        if ($gems['bagMaxRows'] < 1) {
            throw new RuntimeException('settings.gems.bagMaxRows must be >= 1.');
        }

        return $gems['bagMaxRows'];
    }

    private function defaultInventoryMaxRows(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('inventory', $settings) || ! is_array($settings['inventory'])) {
            throw new RuntimeException('settings.inventory missing.');
        }

        $inventory = $settings['inventory'];

        if (! array_key_exists('maxRows', $inventory) || ! is_int($inventory['maxRows'])) {
            throw new RuntimeException('settings.inventory.maxRows missing.');
        }

        if ($inventory['maxRows'] < 1) {
            throw new RuntimeException('settings.inventory.maxRows must be >= 1.');
        }

        return $inventory['maxRows'];
    }

    /**
     * @return array{
     *     silver: int,
     *     gold: int,
     *     strength: int,
     *     agility: int,
     *     instinct: int,
     *     vitality: int,
     *     statPoints: int,
     *     level: int,
     *     exp: int
     * }
     */
    private function onboardingStartConfig(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('start', $onboarding) || ! is_array($onboarding['start'])) {
            throw new RuntimeException('onboarding.start missing.');
        }

        $start = $onboarding['start'];

        return [
            'silver' => $this->intField($start, 'silver'),
            'gold' => $this->intField($start, 'gold'),
            'strength' => $this->intField($start, 'strength'),
            'agility' => $this->intField($start, 'agility'),
            'instinct' => $this->intField($start, 'instinct'),
            'vitality' => $this->intField($start, 'vitality'),
            'statPoints' => $this->intField($start, 'statPoints'),
            'level' => $this->intField($start, 'level'),
            'exp' => $this->intField($start, 'exp'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function intField(array $row, string $key): int
    {
        if (! array_key_exists($key, $row) || ! is_int($row[$key])) {
            throw new RuntimeException("Expected int {$key}.");
        }

        return $row[$key];
    }
}
