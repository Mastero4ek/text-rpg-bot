<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Combat\StanceEnum;
use App\Enums\Enemy\EnemyKindEnum;
use App\Models\Character;
use App\Models\Enemy\EnemyCatalog;
use App\Services\Backpack\LoadoutService;
use App\Support\Enemy;
use App\Support\Mf;
use RuntimeException;

final class EnemyService
{
    public function __construct(
        private readonly CombatService $combat,
        private readonly LoadoutService $loadout,
        private readonly CharacterService $characters,
    ) {}

    public function makeFromCatalog(EnemyCatalog $catalog, Character $character): Enemy
    {
        $catalog->loadMissing('drops');

        if ($catalog->kind === EnemyKindEnum::MIRROR) {
            return $this->makeMirrorEnemy($catalog, $character);
        }

        return $this->makeFixedEnemy($catalog);
    }

    public function menuLabel(EnemyCatalog $catalog, Character $character): string
    {
        if ($catalog->kind === EnemyKindEnum::FIXED) {
            return __('combat.enemy_menu_fixed', [
                'name' => $catalog->name,
                'level' => $catalog->level,
            ]);
        }

        return __('combat.enemy_menu_mirror', [
            'name' => $catalog->name,
            'level' => $character->level,
        ]);
    }

    public function tutorialCatalog(): EnemyCatalog
    {
        $catalog = EnemyCatalog::query()->find(EnemyCatalog::TUTORIAL_CATALOG_ID);

        if (! $catalog instanceof EnemyCatalog) {
            throw new RuntimeException('Tutorial enemy catalog is missing.');
        }

        return $catalog;
    }

    /**
     * @return list<array{bag_catalog_id: string, chance_pct: int}>
     */
    private static function bakedDrops(EnemyCatalog $catalog): array
    {
        $drops = [];

        foreach ($catalog->drops as $drop) {
            $drops[] = [
                'bag_catalog_id' => $drop->bag_catalog_id,
                'chance_pct' => $drop->chance_pct,
            ];
        }

        return $drops;
    }

    /**
     * @return array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}
     */
    private static function emptyArmorByZone(): array
    {
        return [
            'HEAD' => 0,
            'CHEST' => 0,
            'BELLY' => 0,
            'LEGS' => 0,
        ];
    }

    /**
     * @param  array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}  $armorByZone
     * @return array{HEAD: int, CHEST: int, BELLY: int, LEGS: int}
     */
    private static function scaleArmor(array $armorByZone, int $powerPct): array
    {
        return [
            'HEAD' => self::scalePct($armorByZone['HEAD'], $powerPct),
            'CHEST' => self::scalePct($armorByZone['CHEST'], $powerPct),
            'BELLY' => self::scalePct($armorByZone['BELLY'], $powerPct),
            'LEGS' => self::scalePct($armorByZone['LEGS'], $powerPct),
        ];
    }

    private static function scaleMf(Mf $mf, int $powerPct): Mf
    {
        return new Mf(
            self::scalePct($mf->dodge, $powerPct),
            self::scalePct($mf->antiDodge, $powerPct),
            self::scalePct($mf->crit, $powerPct),
            self::scalePct($mf->antiCrit, $powerPct),
        );
    }

    private static function scalePct(int $value, int $powerPct): int
    {
        return (int) floor($value * $powerPct / 100);
    }

    private function makeFixedEnemy(EnemyCatalog $catalog): Enemy
    {
        if ($catalog->strength === null || $catalog->agility === null || $catalog->instinct === null || $catalog->vitality === null) {
            throw new RuntimeException("FIXED enemy {$catalog->catalog_id} is missing stats.");
        }

        if ($catalog->level === null || $catalog->max_hp === null || $catalog->weapon_damage === null) {
            throw new RuntimeException("FIXED enemy {$catalog->catalog_id} is missing combat fields.");
        }

        if ($catalog->mf_dodge === null || $catalog->mf_anti_dodge === null || $catalog->mf_crit === null || $catalog->mf_anti_crit === null) {
            throw new RuntimeException("FIXED enemy {$catalog->catalog_id} is missing MF.");
        }

        $maxStamina = $this->combat->maxStamina($catalog->strength);

        return new Enemy(
            $catalog->name,
            $catalog->level,
            $catalog->strength,
            $catalog->agility,
            $catalog->instinct,
            $catalog->vitality,
            $catalog->max_hp,
            $catalog->max_hp,
            $catalog->weapon_damage,
            new Mf(
                $catalog->mf_dodge,
                $catalog->mf_anti_dodge,
                $catalog->mf_crit,
                $catalog->mf_anti_crit,
            ),
            StanceEnum::DEFEND,
            $maxStamina,
            $maxStamina,
            self::emptyArmorByZone(),
            1,
            1,
            0,
            new Mf(0, 0, 0, 0),
            $catalog->catalog_id,
            $catalog->reward_exp_pct,
            $catalog->reward_silver_min,
            $catalog->reward_silver_max,
            self::bakedDrops($catalog),
        );
    }

    private function makeMirrorEnemy(EnemyCatalog $catalog, Character $character): Enemy
    {
        if ($catalog->power_pct === null) {
            throw new RuntimeException("MIRROR enemy {$catalog->catalog_id} is missing power_pct.");
        }

        $pct = $catalog->power_pct;
        $loadout = $this->loadout->forCharacter($character);
        $strength = self::scalePct($character->strength, $pct);
        $maxHp = self::scalePct($this->characters->maxHp($character), $pct);
        $maxStamina = $this->combat->maxStamina($strength);
        $mainDamage = self::scalePct(
            $this->combat->rollWeaponDamage($loadout->mainHandDamageMin, $loadout->mainHandDamageMax),
            $pct,
        );
        $offDamage = self::scalePct(
            $this->combat->rollWeaponDamage($loadout->offHandDamageMin, $loadout->offHandDamageMax),
            $pct,
        );

        return new Enemy(
            $catalog->name,
            $character->level,
            $strength,
            self::scalePct($character->agility, $pct),
            self::scalePct($character->instinct, $pct),
            self::scalePct($character->vitality, $pct),
            $maxHp,
            $maxHp,
            $mainDamage,
            self::scaleMf($loadout->mfForMainHandAttack(), $pct),
            StanceEnum::DEFEND,
            $maxStamina,
            $maxStamina,
            self::scaleArmor($loadout->armorByZone, $pct),
            $loadout->attackSlots,
            $loadout->blockSlots,
            $offDamage,
            self::scaleMf($loadout->mfForOffHandAttack(), $pct),
            $catalog->catalog_id,
            $catalog->reward_exp_pct,
            $catalog->reward_silver_min,
            $catalog->reward_silver_max,
            self::bakedDrops($catalog),
        );
    }
}
