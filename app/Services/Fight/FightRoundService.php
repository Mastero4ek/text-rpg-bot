<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\CombatService;
use App\Support\Combat\Fighter;
use App\Support\Combat\HitResult;
use App\Support\Enemy;
use App\Support\Equipment\EquippedLoadout;
use App\Support\Mf;
use App\Support\PotionDef;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\DB;

final class FightRoundService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CombatService $combat,
        private readonly FightService $fights,
        private readonly BagService $bag,
        private readonly LoadoutService $loadout,
    ) {}

    public function resolve(Character $player): FightRoundOutcome
    {
        return DB::transaction(function () use ($player): FightRoundOutcome {
            $fresh = Character::query()->lockForUpdate()->find($player->tg_id);

            if ($fresh === null) {
                return FightRoundOutcome::missing();
            }

            if (! $this->fights->exists($fresh->tg_id)) {
                return FightRoundOutcome::missing();
            }

            $fight = Fight::query()->lockForUpdate()->find($fresh->tg_id);

            if ($fight === null) {
                return FightRoundOutcome::missing();
            }

            return $this->runResolve($fresh, $fight, false);
        });
    }

    public function resolveSkip(Character $player): FightRoundOutcome
    {
        return DB::transaction(function () use ($player): FightRoundOutcome {
            $fresh = Character::query()->lockForUpdate()->find($player->tg_id);

            if ($fresh === null) {
                return FightRoundOutcome::missing();
            }

            if (! $this->fights->exists($fresh->tg_id)) {
                return FightRoundOutcome::missing();
            }

            $fight = Fight::query()->lockForUpdate()->find($fresh->tg_id);

            if ($fight === null) {
                return FightRoundOutcome::missing();
            }

            if (! $this->fights->turnTimedOut($fight)) {
                return FightRoundOutcome::continueFight($fresh, $fight);
            }

            return $this->runResolve($fresh, $fight, true);
        });
    }

    private function runResolve(Character $fresh, Fight $fight, bool $skip): FightRoundOutcome
    {
        $enemy = $this->fights->enemy($fight);
        $enemy = $enemy->withStance($this->combat->randomStance());

        $enemyAtk = $this->combat->randomZone();
        $enemyDef = $this->combat->randomZone();
        $enemyAtkSecond = $enemyAtk;
        $enemyDefSecond = $enemyDef;

        if ($enemy->attackSlots >= 2) {
            $enemyAtkSecond = $this->combat->randomZone();
        }

        if ($enemy->blockSlots >= 2) {
            $enemyDefSecond = $this->combat->randomZone();
        }

        $enemyDefendZones = [$enemyDef];

        if ($enemy->blockSlots >= 2) {
            $enemyDefendZones[] = $enemyDefSecond;
        }

        $loadout = $this->loadoutAfterDrop($fresh);
        $logs = [];

        if ($skip) {
            $logs[] = __('combat.turn_timeout');
            $fight->player_stance = null;
            $fight->player_attack = null;
            $fight->player_attack_second = null;
            $fight->player_defend = null;
            $fight->player_defend_second = null;
            $fight->use_potion = false;
        } elseif ($fight->use_potion) {
            if ($fight->player_attack === PlayerAttackEnum::STAMINA_POTION) {
                $profile = ProfileEnum::STAMINA;
            } else {
                $profile = ProfileEnum::HEAL;
            }

            $consumed = $this->bag->consumePotion($fresh->tg_id, $profile);

            if (
                ! $consumed->ok
                || ! $consumed->potion instanceof PotionDef
            ) {
                $logs[] = __('combat.no_potion_turn');
            } else {
                $heal = $consumed->potion->effectValue;

                if ($fresh->username === null) {
                    $drinkName = __('common.you');
                } else {
                    $drinkName = TelegramHtml::escape($fresh->username);
                }

                if ($profile === ProfileEnum::STAMINA) {
                    $fight->player_stamina = $this->characters->clampStamina(
                        $fight->player_stamina + $heal,
                        $fight->player_max_stamina,
                    );
                    $logs[] = __('combat.drink_stamina_potion', [
                        'name' => $drinkName,
                        'heal' => $heal,
                    ]);
                } else {
                    $fight->player_hp = $this->characters->clampHp(
                        $fight->player_hp + $heal,
                        $fight->player_max_hp,
                    );
                    $logs[] = __('combat.drink_potion', [
                        'name' => $drinkName,
                        'heal' => $heal,
                    ]);
                }
            }
        } elseif (
            $fight->player_attack !== null
            && ! $fight->player_attack->isPotion()
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
                    $loadout->mfForMainHandAttack(),
                ),
                $enemy->toFighter(),
                ZoneEnum::from($fight->player_attack->value),
                $enemyDefendZones,
            );
            $enemy = $this->applyHitToEnemy($enemy, $mainHit);
            $fight = $this->applyHitToPlayerAttacker($fight, $mainHit);
            $logs[] = $mainHit->logLine;

            if ($mainHit->pierced) {
                $fight->pierce_count += 1;
            }

            if (
                $enemy->currentHp > 0
                && $fight->player_attack_second !== null
                && ! $fight->player_attack_second->isPotion()
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
                        $loadout->mfForOffHandAttack(),
                    ),
                    $enemy->toFighter(),
                    ZoneEnum::from($fight->player_attack_second->value),
                    $enemyDefendZones,
                );
                $enemy = $this->applyHitToEnemy($enemy, $offHit);
                $fight = $this->applyHitToPlayerAttacker($fight, $offHit);
                $logs[] = $offHit->logLine;

                if ($offHit->pierced) {
                    $fight->pierce_count += 1;
                }
            }
        }

        if ($enemy->currentHp > 0 && ! $skip) {
            if ($fight->player_defend !== null) {
                $you = $this->playerFighterWithStance(
                    $fresh,
                    $fight,
                    $loadout,
                    $this->combat->rollWeaponDamage(
                        $loadout->weaponDamageMin,
                        $loadout->weaponDamageMax,
                    ),
                    $loadout->mf,
                );
                $hitBack = $this->combat->calculateHit(
                    $enemy->toFighter(),
                    $you,
                    $enemyAtk,
                    $this->playerDefendZones($fight),
                );
                $fight->player_hp = max(0, $fight->player_hp - $hitBack->dmg);
                $enemy = $this->applyHitToEnemyAttacker($enemy, $hitBack);
                $fight = $this->applyHitToPlayerDefender($fight, $hitBack);
                $logs[] = $hitBack->logLine;

                if ($hitBack->pierced) {
                    $fight->pierce_count += 1;
                }

                if ($fight->player_hp > 0 && $enemy->attackSlots >= 2) {
                    $offHitBack = $this->combat->calculateHit(
                        $enemy->toOffHandFighter(),
                        $you,
                        $enemyAtkSecond,
                        $this->playerDefendZones($fight),
                    );
                    $fight->player_hp = max(0, $fight->player_hp - $offHitBack->dmg);
                    $enemy = $this->applyHitToEnemyAttacker($enemy, $offHitBack);
                    $fight = $this->applyHitToPlayerDefender($fight, $offHitBack);
                    $logs[] = $offHitBack->logLine;

                    if ($offHitBack->pierced) {
                        $fight->pierce_count += 1;
                    }
                }
            }
        } elseif ($enemy->currentHp > 0 && $skip) {
            $you = $this->playerFighterWithStance(
                $fresh,
                $fight,
                $loadout,
                $this->combat->rollWeaponDamage(
                    $loadout->weaponDamageMin,
                    $loadout->weaponDamageMax,
                ),
                $loadout->mf,
            );
            $hitBack = $this->combat->calculateHit(
                $enemy->toFighter(),
                $you,
                $enemyAtk,
                [],
            );
            $fight->player_hp = max(0, $fight->player_hp - $hitBack->dmg);
            $enemy = $this->applyHitToEnemyAttacker($enemy, $hitBack);
            $logs[] = $hitBack->logLine;

            if ($hitBack->pierced) {
                $fight->pierce_count += 1;
            }

            if ($fight->player_hp > 0 && $enemy->attackSlots >= 2) {
                $offHitBack = $this->combat->calculateHit(
                    $enemy->toOffHandFighter(),
                    $you,
                    $enemyAtkSecond,
                    [],
                );
                $fight->player_hp = max(0, $fight->player_hp - $offHitBack->dmg);
                $enemy = $this->applyHitToEnemyAttacker($enemy, $offHitBack);
                $logs[] = $offHitBack->logLine;

                if ($offHitBack->pierced) {
                    $fight->pierce_count += 1;
                }
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
        $fresh->current_stamina = $fight->player_stamina;
        $fresh->last_stamina_update = now();
        $fresh->save();
        $this->fights->save($fight);

        if ($enemy->currentHp <= 0) {
            return FightRoundOutcome::win($fresh, $fight);
        }

        if ($fight->player_hp <= 0) {
            return FightRoundOutcome::lose($fresh, $fight);
        }

        $fight = $this->fights->scheduleTurn($fight);

        return FightRoundOutcome::continueFight($fresh, $fight);
    }

    private function loadoutAfterDrop(Character $character): EquippedLoadout
    {
        $character = $this->loadout->dropUnmetEquipped($character);

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

    private function applyHitToEnemy(Enemy $enemy, HitResult $hit): Enemy
    {
        $enemy = $enemy->withCurrentHp(max(0, $enemy->currentHp - $hit->dmg));
        $drain = $this->combat->staminaDrainForDefender($hit->dodged);

        return $enemy->withStamina(
            $this->combat->clampStamina($enemy->stamina - $drain, $enemy->maxStamina),
        );
    }

    private function applyHitToEnemyAttacker(Enemy $enemy, HitResult $hit): Enemy
    {
        $drain = $this->combat->staminaDrainForAttacker(
            $enemy->stance,
            $hit->critical,
            $hit->pierced,
        );

        return $enemy->withStamina(
            $this->combat->clampStamina($enemy->stamina - $drain, $enemy->maxStamina),
        );
    }

    private function applyHitToPlayerAttacker(Fight $fight, HitResult $hit): Fight
    {
        if ($fight->player_stance === null) {
            $stance = StanceEnum::DEFEND;
        } else {
            $stance = $fight->player_stance;
        }

        $drain = $this->combat->staminaDrainForAttacker(
            $stance,
            $hit->critical,
            $hit->pierced,
        );
        $fight->player_stamina = $this->combat->clampStamina(
            $fight->player_stamina - $drain,
            $fight->player_max_stamina,
        );

        return $fight;
    }

    private function applyHitToPlayerDefender(Fight $fight, HitResult $hit): Fight
    {
        $drain = $this->combat->staminaDrainForDefender($hit->dodged);
        $fight->player_stamina = $this->combat->clampStamina(
            $fight->player_stamina - $drain,
            $fight->player_max_stamina,
        );

        return $fight;
    }

    private function playerFighterWithStance(
        Character $character,
        Fight $fight,
        EquippedLoadout $loadout,
        int $weaponDamage,
        Mf $weaponMf,
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
            $weaponMf,
            $stance,
            $loadout->armorByZone,
            $fight->player_stamina,
            $fight->player_max_stamina,
        );
    }
}
