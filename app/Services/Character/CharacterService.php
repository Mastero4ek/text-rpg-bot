<?php

declare(strict_types=1);

namespace App\Services\Character;

use App\Enums\ItemTypeEnum;
use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;
use App\Models\Character;
use App\Services\Game\GameConfig;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\ActionResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CharacterService
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly ShopCatalog $shop,
    ) {}

    public function clampHp(int $hp, int $maxHp): int
    {
        return max(0, min($maxHp, $hp));
    }

    public function baseMaxHp(int $vitality): int
    {
        $maxHp = $this->characterMaxHpConfig();

        return $maxHp['base'] + $vitality * $maxHp['perVitality'];
    }

    public function armorBonus(Character $character): int
    {
        if ($character->armor_id === null) {
            return 0;
        }

        if (! $this->shop->hasItem($character->armor_id)) {
            return 0;
        }

        $item = $this->shop->findItem($character->armor_id);

        if ($item->itemType !== ItemTypeEnum::ARMOR) {
            return 0;
        }

        return $item->statBonus;
    }

    public function maxHp(Character $character): int
    {
        return $this->baseMaxHp($character->vitality) + $this->armorBonus($character);
    }

    public function expNeed(int $level): int
    {
        $levelCfg = $this->characterLevelConfig();

        return $level * $levelCfg['expPerLevelMultiplier'];
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

            $character = new Character;
            $character->tg_id = $tgId;
            $character->username = null;
            $character->location = null;
            $character->onboarding_step = OnboardingStepEnum::NICK;
            $character->level = $start['level'];
            $character->exp = $start['exp'];
            $character->gold = $start['gold'];
            $character->strength = $start['strength'];
            $character->agility = $start['agility'];
            $character->instinct = $start['instinct'];
            $character->vitality = $start['vitality'];
            $character->current_hp = $hp;
            $character->last_hp_update = now();
            $character->weapon_id = null;
            $character->armor_id = null;
            $character->stat_points = $start['statPoints'];
            $character->potions = $start['potions'];
            $character->arena_points = 0;
            $character->premium_until = null;
            $character->save();

            return $this->findByTgId($tgId);
        });
    }

    public function applyRegen(Character $character): Character
    {
        return DB::transaction(function () use ($character): Character {
            $cap = $this->maxHp($character);
            $regen = $this->characterRegenConfig();

            if ($character->current_hp < $cap) {
                $secondsPassed = (int) $character->last_hp_update->diffInSeconds(now());
                $hpPerTick = (int) floor(
                    $character->vitality / $regen['vitalityDivisor'] + $regen['vitalityBonus']
                );
                $hpToRegen = (int) floor($secondsPassed / $regen['tickSeconds']) * $hpPerTick;

                if ($hpToRegen > 0) {
                    $character->current_hp = min($cap, $character->current_hp + $hpToRegen);
                    $character->last_hp_update = now();
                    $character->save();
                }
            } elseif ($character->current_hp >= $cap) {
                $character->last_hp_update = now();
            }

            return $character;
        });
    }

    public function getFresh(int $tgId): Character
    {
        $character = Character::query()->find($tgId);

        if ($character === null) {
            throw (new ModelNotFoundException)->setModel(Character::class, [$tgId]);
        }

        return $this->applyRegen($character);
    }

    public function tryLevelUp(Character $character): bool
    {
        $levelCfg = $this->characterLevelConfig();

        if ($character->level < 1 || $character->level >= $levelCfg['max']) {
            return false;
        }

        $leveled = false;

        while ($character->level < $levelCfg['max'] && $character->exp >= $this->expNeed($character->level)) {
            $character->exp -= $this->expNeed($character->level);
            $character->level += 1;
            $character->stat_points += $levelCfg['statPointsPerLevel'];
            $leveled = true;
        }

        return $leveled;
    }

    public function addExpGold(Character $character, int $expGain, int $goldGain): Character
    {
        return DB::transaction(function () use ($character, $expGain, $goldGain): Character {
            $levelCfg = $this->characterLevelConfig();

            if ($character->level < $levelCfg['max']) {
                $character->exp += $expGain;
            }

            $character->gold += $goldGain;
            $this->tryLevelUp($character);
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
                $gain = $this->characterStatSpendConfig()['vitalityHpGain'];
                $character->current_hp = $this->clampHp(
                    $character->current_hp + $gain,
                    $this->maxHp($character),
                );
            }

            $character->save();

            return ActionResult::ok($character);
        });
    }

    public function profileText(Character $character): string
    {
        $cap = $this->maxHp($character);
        $levelCfg = $this->characterLevelConfig();

        if ($character->level >= $levelCfg['max'] || $character->level < 1) {
            $need = '—';
        } else {
            $need = $character->exp . '/' . $this->expNeed($character->level);
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

        if ($character->weapon_id === null) {
            $weaponName = __('common.no_weapon');
        } elseif ($this->shop->hasItem($character->weapon_id)) {
            $weaponName = $this->shop->findItem($character->weapon_id)->itemName;
        } else {
            $weaponName = __('common.no_weapon');
        }

        if ($character->armor_id === null) {
            $armorName = __('common.no_armor');
        } elseif ($this->shop->hasItem($character->armor_id)) {
            $armorName = $this->shop->findItem($character->armor_id)->itemName;
        } else {
            $armorName = __('common.no_armor');
        }

        $lines = [
            __('profile.card', [
                'name' => $name,
                'city' => $city,
                'level' => $character->level,
                'hp' => $character->current_hp,
                'maxHp' => $cap,
                'gold' => $character->gold,
                'potions' => $character->potions,
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
        $lines[] = __('profile.weapon_line', ['weapon' => $weaponName]);
        $lines[] = __('profile.armor_line', ['armor' => $armorName]);

        return implode("\n", $lines);
    }

    public function usernameTakenByOther(string $username, int $tgId): bool
    {
        return Character::query()
            ->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])
            ->where('tg_id', '!=', $tgId)
            ->exists();
    }

    /**
     * @return array{base: int, perVitality: int}
     */
    private function characterMaxHpConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('maxHp', $character) || ! is_array($character['maxHp'])) {
            throw new RuntimeException('character.maxHp missing.');
        }

        return [
            'base' => $this->intField($character['maxHp'], 'base'),
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
     * @return array{max: int, expPerLevelMultiplier: int, statPointsPerLevel: int}
     */
    private function characterLevelConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('level', $character) || ! is_array($character['level'])) {
            throw new RuntimeException('character.level missing.');
        }

        return [
            'max' => $this->intField($character['level'], 'max'),
            'expPerLevelMultiplier' => $this->intField($character['level'], 'expPerLevelMultiplier'),
            'statPointsPerLevel' => $this->intField($character['level'], 'statPointsPerLevel'),
        ];
    }

    /**
     * @return array{vitalityHpGain: int}
     */
    private function characterStatSpendConfig(): array
    {
        $character = $this->config->character();

        if (! array_key_exists('statSpend', $character) || ! is_array($character['statSpend'])) {
            throw new RuntimeException('character.statSpend missing.');
        }

        return [
            'vitalityHpGain' => $this->intField($character['statSpend'], 'vitalityHpGain'),
        ];
    }

    /**
     * @return array{
     *     gold: int,
     *     strength: int,
     *     agility: int,
     *     instinct: int,
     *     vitality: int,
     *     statPoints: int,
     *     level: int,
     *     exp: int,
     *     potions: int
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
            'gold' => $this->intField($start, 'gold'),
            'strength' => $this->intField($start, 'strength'),
            'agility' => $this->intField($start, 'agility'),
            'instinct' => $this->intField($start, 'instinct'),
            'vitality' => $this->intField($start, 'vitality'),
            'statPoints' => $this->intField($start, 'statPoints'),
            'level' => $this->intField($start, 'level'),
            'exp' => $this->intField($start, 'exp'),
            'potions' => $this->intField($start, 'potions'),
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
