<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\FightPlayerAttackEnum;
use App\Enums\FightStepEnum;
use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Services\Combat\CombatService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\LoadoutService;
use App\Support\Game\EquippedLoadout;
use App\Support\Game\Fighter;
use Illuminate\Support\Facades\DB;

final class FightRoundService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CombatService $combat,
        private readonly FightService $fights,
        private readonly InventoryService $inventory,
        private readonly LoadoutService $loadout,
    ) {}

    public function resolve(Character $player): FightRoundOutcome
    {
        return DB::transaction(function () use ($player): FightRoundOutcome {
            $fresh = Character::query()->find($player->tg_id);

            if ($fresh === null) {
                return FightRoundOutcome::missing();
            }

            if (! $this->fights->exists($fresh->tg_id)) {
                return FightRoundOutcome::missing();
            }

            $fight = $this->fights->findByTgId($fresh->tg_id);
            $enemy = $this->fights->enemy($fight);
            $enemy = $enemy->withStance($this->combat->randomStance());

            $enemyAtk = $this->combat->randomZone();
            $enemyDef = $this->combat->randomZone();
            $loadout = $this->loadoutAfterDrop($fresh);
            $logs = [];

            if ($fight->use_potion) {
                if ($fresh->potions <= 0) {
                    $logs[] = __('combat.no_potion_turn');
                } else {
                    $fresh->potions -= 1;
                    $heal = $this->combat->potionHeal();
                    $fight->player_hp = $this->characters->clampHp(
                        $fight->player_hp + $heal,
                        $fight->player_max_hp,
                    );

                    if ($fresh->username === null) {
                        $drinkName = __('common.you');
                    } else {
                        $drinkName = $fresh->username;
                    }

                    $logs[] = __('combat.drink_potion', [
                        'name' => $drinkName,
                        'heal' => $heal,
                    ]);
                }
            } elseif (
                $fight->player_attack !== null
                && $fight->player_attack !== FightPlayerAttackEnum::POTION
            ) {
                $mainHit = $this->combat->calculateHit(
                    $this->playerFighterWithStance(
                        $fresh,
                        $fight,
                        $loadout,
                        $this->combat->rollWeaponDamage(
                            $loadout->mainHandDamageMin,
                            $loadout->mainHandDamageMax,
                        ),
                    ),
                    $enemy->toFighter(),
                    ZoneEnum::from($fight->player_attack->value),
                    [$enemyDef],
                );
                $enemy = $enemy->withCurrentHp(max(0, $enemy->currentHp - $mainHit->dmg));
                $logs[] = $mainHit->logLine;

                if ($mainHit->pierced) {
                    $fight->pierce_count += 1;
                }

                if (
                    $enemy->currentHp > 0
                    && $fight->player_attack_second !== null
                    && $fight->player_attack_second !== FightPlayerAttackEnum::POTION
                ) {
                    $offHit = $this->combat->calculateHit(
                        $this->playerFighterWithStance(
                            $fresh,
                            $fight,
                            $loadout,
                            $this->combat->rollWeaponDamage(
                                $loadout->offHandDamageMin,
                                $loadout->offHandDamageMax,
                            ),
                        ),
                        $enemy->toFighter(),
                        ZoneEnum::from($fight->player_attack_second->value),
                        [$enemyDef],
                    );
                    $enemy = $enemy->withCurrentHp(max(0, $enemy->currentHp - $offHit->dmg));
                    $logs[] = $offHit->logLine;

                    if ($offHit->pierced) {
                        $fight->pierce_count += 1;
                    }
                }
            }

            if ($enemy->currentHp > 0 && $fight->player_defend !== null) {
                $you = $this->playerFighterWithStance(
                    $fresh,
                    $fight,
                    $loadout,
                    $this->combat->rollWeaponDamage(
                        $loadout->weaponDamageMin,
                        $loadout->weaponDamageMax,
                    ),
                );
                $hitBack = $this->combat->calculateHit(
                    $enemy->toFighter(),
                    $you,
                    $enemyAtk,
                    $this->playerDefendZones($fight),
                );
                $fight->player_hp = max(0, $fight->player_hp - $hitBack->dmg);
                $logs[] = $hitBack->logLine;

                if ($hitBack->pierced) {
                    $fight->pierce_count += 1;
                }
            }

            $combined = $fight->log;

            foreach ($logs as $line) {
                $combined[] = $line;
            }

            $fight->enemy = $enemy->toArray();
            $fight->log = $combined;
            $fight->step = FightStepEnum::STANCE;
            $fight->player_stance = null;
            $fight->player_attack = null;
            $fight->player_attack_second = null;
            $fight->player_defend = null;
            $fight->player_defend_second = null;
            $fight->use_potion = false;
            $fresh->current_hp = $fight->player_hp;
            $fresh->last_hp_update = now();
            $fresh->save();
            $this->fights->save($fight);

            if ($enemy->currentHp <= 0) {
                return FightRoundOutcome::win($fresh, $fight);
            }

            if ($fight->player_hp <= 0) {
                return FightRoundOutcome::lose($fresh, $fight);
            }

            return FightRoundOutcome::continueFight($fresh, $fight);
        });
    }

    private function loadoutAfterDrop(Character $character): EquippedLoadout
    {
        $character = $this->inventory->dropUnmetEquipped($character);

        return $this->loadout->forCharacter($character);
    }

    /**
     * @return list<ZoneEnum>
     */
    private function playerDefendZones(Fight $fight): array
    {
        $zones = [];

        if ($fight->player_defend instanceof ZoneEnum) {
            $zones[] = $fight->player_defend;
        }

        if ($fight->player_defend_second instanceof ZoneEnum) {
            $zones[] = $fight->player_defend_second;
        }

        return $zones;
    }

    private function playerFighterWithStance(
        Character $character,
        Fight $fight,
        EquippedLoadout $loadout,
        int $weaponDamage,
    ): Fighter {
        if ($fight->player_stance === null) {
            $stance = StanceEnum::DEFEND;
        } else {
            $stance = $fight->player_stance;
        }

        if ($character->username === null) {
            $name = __('common.you');
        } else {
            $name = $character->username;
        }

        return new Fighter(
            $name,
            $character->strength,
            $character->agility,
            $character->instinct,
            $character->vitality,
            $weaponDamage,
            $loadout->mf,
            $stance,
            $loadout->armorByZone,
        );
    }
}
