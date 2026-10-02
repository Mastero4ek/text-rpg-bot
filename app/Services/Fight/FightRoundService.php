<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\FightPlayerAttackEnum;
use App\Enums\FightStepEnum;
use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Combat\CombatService;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\Fighter;
use Illuminate\Support\Facades\DB;

final class FightRoundService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CombatService $combat,
        private readonly FightService $fights,
        private readonly ShopCatalog $shop,
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
            $atkFighter = $this->playerFighter($fresh);

            if ($fight->player_stance === null) {
                $atkFighter = new Fighter(
                    $atkFighter->name,
                    $atkFighter->strength,
                    $atkFighter->agility,
                    $atkFighter->instinct,
                    $atkFighter->vitality,
                    $atkFighter->weaponDamage,
                    $atkFighter->weaponMf,
                    StanceEnum::DEFEND,
                );
            } else {
                $atkFighter = new Fighter(
                    $atkFighter->name,
                    $atkFighter->strength,
                    $atkFighter->agility,
                    $atkFighter->instinct,
                    $atkFighter->vitality,
                    $atkFighter->weaponDamage,
                    $atkFighter->weaponMf,
                    $fight->player_stance,
                );
            }

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
                $hit = $this->combat->calculateHit(
                    $atkFighter,
                    $enemy->toFighter(),
                    ZoneEnum::from($fight->player_attack->value),
                    $enemyDef,
                );
                $enemy = $enemy->withCurrentHp(max(0, $enemy->currentHp - $hit->dmg));
                $logs[] = $hit->logLine;
            }

            if ($enemy->currentHp > 0 && $fight->player_defend !== null) {
                $you = $this->playerFighter($fresh);

                if ($fight->player_stance === null) {
                    $stance = StanceEnum::DEFEND;
                } else {
                    $stance = $fight->player_stance;
                }

                if ($fresh->username === null) {
                    $youName = __('common.you');
                } else {
                    $youName = $fresh->username;
                }

                $you = new Fighter(
                    $youName,
                    $you->strength,
                    $you->agility,
                    $you->instinct,
                    $you->vitality,
                    $you->weaponDamage,
                    $you->weaponMf,
                    $stance,
                );

                $hitBack = $this->combat->calculateHit(
                    $enemy->toFighter(),
                    $you,
                    $enemyAtk,
                    $fight->player_defend,
                );
                $fight->player_hp = max(0, $fight->player_hp - $hitBack->dmg);
                $logs[] = $hitBack->logLine;
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
            $fight->player_defend = null;
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

    private function playerFighter(Character $character): Fighter
    {
        $weaponDef = null;

        if ($character->weapon_id !== null && $this->shop->hasItem($character->weapon_id)) {
            $weaponDef = $this->shop->findItem($character->weapon_id);
        }

        return $this->combat->fighterFromPlayerDefaultName($character, $weaponDef);
    }
}
