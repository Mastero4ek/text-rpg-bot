<?php

declare(strict_types=1);

namespace App\Services\Pvp;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Models\Character\Character;
use App\Models\Fight;
use App\Models\Pvp\PvpDuel;
use App\Services\Backpack\LoadoutService;
use App\Services\CombatService;
use App\Services\Fight\FightService;
use App\Support\Combat\Fighter;
use App\Support\Combat\HitResult;
use App\Support\Equipment\EquippedLoadout;
use App\Support\Mf;
use Illuminate\Support\Facades\DB;

final class PvpRoundService
{
    public function __construct(
        private readonly CombatService $combat,
        private readonly FightService $fights,
        private readonly LoadoutService $loadout,
        private readonly PvpChallengeService $challenges,
    ) {}

    public function submitReadyChoice(Character $player): PvpDuelOutcome
    {
        return DB::transaction(function () use ($player): PvpDuelOutcome {
            $fresh = Character::query()->lockForUpdate()->find($player->tg_id);

            if ($fresh === null || ! $this->fights->exists($fresh->tg_id)) {
                return PvpDuelOutcome::missing();
            }

            $fight = Fight::query()->lockForUpdate()->find($fresh->tg_id);

            if ($fight === null || $fight->kind !== FightKindEnum::PVP || $fight->duel_id === null) {
                return PvpDuelOutcome::missing();
            }

            $duel = PvpDuel::query()->lockForUpdate()->find($fight->duel_id);

            if (! $duel instanceof PvpDuel || $duel->status !== PvpDuelStatusEnum::Active) {
                return PvpDuelOutcome::missing();
            }

            $choice = $this->choiceFromFight($fight, false);
            $this->writeChoice($duel, $fresh->tg_id, $choice);
            $fight = $this->enterWait($fight);

            return $this->resolveIfReady($duel->fresh(), $fresh, $fight);
        });
    }

    public function submitSkip(Character $player): PvpDuelOutcome
    {
        return DB::transaction(function () use ($player): PvpDuelOutcome {
            $fresh = Character::query()->lockForUpdate()->find($player->tg_id);

            if ($fresh === null || ! $this->fights->exists($fresh->tg_id)) {
                return PvpDuelOutcome::missing();
            }

            $fight = Fight::query()->lockForUpdate()->find($fresh->tg_id);

            if ($fight === null || $fight->kind !== FightKindEnum::PVP || $fight->duel_id === null) {
                return PvpDuelOutcome::missing();
            }

            if ($fight->step === FightStepEnum::WAIT) {
                return PvpDuelOutcome::waiting($fresh, $fight);
            }

            $duel = PvpDuel::query()->lockForUpdate()->find($fight->duel_id);

            if (! $duel instanceof PvpDuel || $duel->status !== PvpDuelStatusEnum::Active) {
                return PvpDuelOutcome::missing();
            }

            if ($this->sideChoice($duel, $fresh->tg_id) !== null) {
                return PvpDuelOutcome::waiting($fresh, $fight);
            }

            $actorName = $fresh->username;

            if ($actorName === null) {
                $actorName = __('common.unnamed');
            }

            $this->writeChoice($duel, $fresh->tg_id, [
                'skipped' => true,
                'skip_log' => __('combat.turn_timeout', [
                    'name' => $this->combat->logName($actorName, $fresh->level),
                ]),
                'stance' => null,
                'attack' => null,
                'attack_second' => null,
                'defend' => null,
                'defend_second' => null,
                'use_potion' => false,
            ]);
            $fight = $this->enterWait($fight);

            return $this->resolveIfReady($duel->fresh(), $fresh, $fight);
        });
    }

    private function resolveIfReady(PvpDuel $duel, Character $actor, Fight $actorFight): PvpDuelOutcome
    {
        if ($duel->a_choice === null || $duel->b_choice === null) {
            return PvpDuelOutcome::waiting($actor, $actorFight);
        }

        return $this->applyRound($duel);
    }

    private function applyRound(PvpDuel $duel): PvpDuelOutcome
    {
        $fightA = Fight::query()->lockForUpdate()->find($duel->a_tg_id);
        $fightB = Fight::query()->lockForUpdate()->find($duel->b_tg_id);
        $charA = Character::query()->lockForUpdate()->find($duel->a_tg_id);
        $charB = Character::query()->lockForUpdate()->find($duel->b_tg_id);

        if (
            ! $fightA instanceof Fight
            || ! $fightB instanceof Fight
            || ! $charA instanceof Character
            || ! $charB instanceof Character
            || $duel->a_choice === null
            || $duel->b_choice === null
        ) {
            return PvpDuelOutcome::missing();
        }

        $choiceA = $duel->a_choice;
        $choiceB = $duel->b_choice;
        $loadoutA = $this->loadoutAfterDrop($charA);
        $loadoutB = $this->loadoutAfterDrop($charB);

        $hpABefore = $fightA->player_hp;
        $hpBBefore = $fightB->player_hp;
        $logs = [];

        if ($this->isSkipped($choiceA)) {
            $logs[] = (string) $choiceA['skip_log'];
        }

        if ($this->isSkipped($choiceB)) {
            $logs[] = (string) $choiceB['skip_log'];
        }

        $stanceA = $this->stanceFromChoice($choiceA);
        $stanceB = $this->stanceFromChoice($choiceB);
        $defendA = $this->defendZones($choiceA, $loadoutA);
        $defendB = $this->defendZones($choiceB, $loadoutB);

        $enemyA = $this->fights->enemy($fightA)->withStance($stanceB);
        $enemyB = $this->fights->enemy($fightB)->withStance($stanceA);

        if (! $this->isSkipped($choiceA) && ! $this->isPotion($choiceA)) {
            $hit = $this->strike(
                $charA,
                $fightA,
                $loadoutA,
                $stanceA,
                $choiceA,
                true,
                $charB,
                $fightB,
                $loadoutB,
                $stanceB,
                $defendB,
            );

            if ($hit instanceof HitResult) {
                $fightB->player_hp = max(0, $fightB->player_hp - $hit->dmg);
                $enemyA = $enemyA->withCurrentHp($fightB->player_hp);
                $fightA = $this->applyAttackerStamina($fightA, $stanceA, $hit);
                $fightB = $this->applyDefenderStamina($fightB, $hit);
                $logs[] = $hit->logLine;

                if ($hit->pierced) {
                    $fightA->pierce_count += 1;
                }

                if ($fightB->player_hp > 0 && $this->hasSecondAttack($choiceA, $loadoutA)) {
                    $offHit = $this->strike(
                        $charA,
                        $fightA,
                        $loadoutA,
                        $stanceA,
                        $choiceA,
                        false,
                        $charB,
                        $fightB,
                        $loadoutB,
                        $stanceB,
                        $defendB,
                    );

                    if ($offHit instanceof HitResult) {
                        $fightB->player_hp = max(0, $fightB->player_hp - $offHit->dmg);
                        $enemyA = $enemyA->withCurrentHp($fightB->player_hp);
                        $fightA = $this->applyAttackerStamina($fightA, $stanceA, $offHit);
                        $fightB = $this->applyDefenderStamina($fightB, $offHit);
                        $logs[] = $offHit->logLine;

                        if ($offHit->pierced) {
                            $fightA->pierce_count += 1;
                        }
                    }
                }
            }
        }

        if ($fightB->player_hp > 0 && ! $this->isSkipped($choiceB) && ! $this->isPotion($choiceB)) {
            $hit = $this->strike(
                $charB,
                $fightB,
                $loadoutB,
                $stanceB,
                $choiceB,
                true,
                $charA,
                $fightA,
                $loadoutA,
                $stanceA,
                $defendA,
            );

            if ($hit instanceof HitResult) {
                $fightA->player_hp = max(0, $fightA->player_hp - $hit->dmg);
                $enemyB = $enemyB->withCurrentHp($fightA->player_hp);
                $fightB = $this->applyAttackerStamina($fightB, $stanceB, $hit);
                $fightA = $this->applyDefenderStamina($fightA, $hit);
                $logs[] = $hit->logLine;

                if ($hit->pierced) {
                    $fightB->pierce_count += 1;
                }

                if ($fightA->player_hp > 0 && $this->hasSecondAttack($choiceB, $loadoutB)) {
                    $offHit = $this->strike(
                        $charB,
                        $fightB,
                        $loadoutB,
                        $stanceB,
                        $choiceB,
                        false,
                        $charA,
                        $fightA,
                        $loadoutA,
                        $stanceA,
                        $defendA,
                    );

                    if ($offHit instanceof HitResult) {
                        $fightA->player_hp = max(0, $fightA->player_hp - $offHit->dmg);
                        $enemyB = $enemyB->withCurrentHp($fightA->player_hp);
                        $fightB = $this->applyAttackerStamina($fightB, $stanceB, $offHit);
                        $fightA = $this->applyDefenderStamina($fightA, $offHit);
                        $logs[] = $offHit->logLine;

                        if ($offHit->pierced) {
                            $fightB->pierce_count += 1;
                        }
                    }
                }
            }
        } elseif ($fightB->player_hp > 0 && $this->isSkipped($choiceA) && ! $this->isSkipped($choiceB) && ! $this->isPotion($choiceB)) {
            // B hits open A already handled above when A not skipped.
        }

        if ($fightB->player_hp > 0 && $this->isSkipped($choiceA) && ! $this->isSkipped($choiceB) && ! $this->isPotion($choiceB)) {
            // already struck above if A not dead from... A skipped so B strike ran in second block only if A hp > 0 from start of B turn.
        }

        // Open hits when defender skipped: already covered — defend zones empty when skipped.

        $enemyA = $enemyA->withCurrentHp($fightB->player_hp)->withStamina($fightB->player_stamina);
        $enemyB = $enemyB->withCurrentHp($fightA->player_hp)->withStamina($fightA->player_stamina);

        $atkA = $this->attackZone($choiceA, true);
        $atkA2 = $this->attackZone($choiceA, false);
        $atkB = $this->attackZone($choiceB, true);
        $atkB2 = $this->attackZone($choiceB, false);
        $defA = $this->firstDefend($choiceA);
        $defA2 = $this->secondDefend($choiceA);
        $defB = $this->firstDefend($choiceB);
        $defB2 = $this->secondDefend($choiceB);

        $fightA = $this->fights->appendLogLines($fightA, $logs, max(1, $duel->round));
        $fightB = $this->fights->appendLogLines($fightB, $logs, max(1, $duel->round));

        $fightA->enemy = $enemyA->toArray();
        $fightB->enemy = $enemyB->toArray();
        $fightA->last_round = $this->lastRound(
            $this->isSkipped($choiceA),
            $hpABefore,
            $fightA->player_hp,
            $hpBBefore,
            $fightB->player_hp,
            $stanceA,
            $this->attackEnum($choiceA, true),
            $this->attackEnum($choiceA, false),
            $defA,
            $defA2,
            $stanceB,
            $atkB ?? ZoneEnum::CHEST,
            $atkB2,
            $defB ?? ZoneEnum::CHEST,
            $defB2,
        );
        $fightB->last_round = $this->lastRound(
            $this->isSkipped($choiceB),
            $hpBBefore,
            $fightB->player_hp,
            $hpABefore,
            $fightA->player_hp,
            $stanceB,
            $this->attackEnum($choiceB, true),
            $this->attackEnum($choiceB, false),
            $defB,
            $defB2,
            $stanceA,
            $atkA ?? ZoneEnum::CHEST,
            $atkA2,
            $defA ?? ZoneEnum::CHEST,
            $defA2,
        );

        $fightA->step = FightStepEnum::STANCE;
        $fightB->step = FightStepEnum::STANCE;
        $this->clearWizard($fightA);
        $this->clearWizard($fightB);

        $charA->current_hp = $fightA->player_hp;
        $charA->current_stamina = $fightA->player_stamina;
        $charA->last_hp_update = now();
        $charA->last_stamina_update = now();
        $charA->save();

        $charB->current_hp = $fightB->player_hp;
        $charB->current_stamina = $fightB->player_stamina;
        $charB->last_hp_update = now();
        $charB->last_stamina_update = now();
        $charB->save();

        $this->fights->save($fightA);
        $this->fights->save($fightB);

        $duel->a_choice = null;
        $duel->b_choice = null;
        $duel->round += 1;
        $duel->round_seq += 1;

        if ($this->isSkipped($choiceA) && $this->isSkipped($choiceB)) {
            $duel->mutual_skip_streak += 1;
        } else {
            $duel->mutual_skip_streak = 0;
        }

        $duel->save();

        if ($duel->mutual_skip_streak >= $this->challenges->mutualSkipAbortRounds()) {
            $abortLine = __('combat.pvp_afk_abort_log');
            $logRound = max(1, $duel->round - 1);
            $fightA = $this->fights->appendLogLines($fightA, [$abortLine], $logRound);
            $fightB = $this->fights->appendLogLines($fightB, [$abortLine], $logRound);
            $this->fights->save($fightA);
            $this->fights->save($fightB);

            $this->persistDuelLog($duel, $fightA);
            $duel->status = PvpDuelStatusEnum::Done;
            $duel->winner_tg_id = null;
            $duel->save();

            return PvpDuelOutcome::aborted($charA, $fightA, $charB, $fightB);
        }

        if ($fightB->player_hp <= 0 && $fightA->player_hp <= 0) {
            // simultaneous death — challenger (A) wins on tie-break
            $this->persistDuelLog($duel, $fightA);
            $duel->status = PvpDuelStatusEnum::Done;
            $duel->winner_tg_id = $charA->tg_id;
            $duel->save();

            return PvpDuelOutcome::finished($charA, $fightA, $charB, $fightB, $charA->tg_id);
        }

        if ($fightB->player_hp <= 0) {
            $this->persistDuelLog($duel, $fightA);
            $duel->status = PvpDuelStatusEnum::Done;
            $duel->winner_tg_id = $charA->tg_id;
            $duel->save();

            return PvpDuelOutcome::finished($charA, $fightA, $charB, $fightB, $charA->tg_id);
        }

        if ($fightA->player_hp <= 0) {
            $this->persistDuelLog($duel, $fightA);
            $duel->status = PvpDuelStatusEnum::Done;
            $duel->winner_tg_id = $charB->tg_id;
            $duel->save();

            return PvpDuelOutcome::finished($charA, $fightA, $charB, $fightB, $charB->tg_id);
        }

        $fightA = $this->fights->scheduleTurn($fightA);
        $fightB = $this->fights->scheduleTurn($fightB);

        return PvpDuelOutcome::continueFight($charA, $fightA, $charB, $fightB);
    }

    /**
     * @param  array<string, mixed>  $choice
     * @param  list<ZoneEnum>  $defendZones
     */
    private function strike(
        Character $attacker,
        Fight $attackerFight,
        EquippedLoadout $attackerLoadout,
        StanceEnum $attackerStance,
        array $choice,
        bool $mainHand,
        Character $defender,
        Fight $defenderFight,
        EquippedLoadout $defenderLoadout,
        StanceEnum $defenderStance,
        array $defendZones,
    ): ?HitResult {
        $zone = $this->attackZone($choice, $mainHand);

        if (! $zone instanceof ZoneEnum) {
            return null;
        }

        if ($mainHand) {
            $damage = $this->combat->rollWeaponDamage(
                $attackerLoadout->mainHandDamageMin,
                $attackerLoadout->mainHandDamageMax,
            );
            $mf = $attackerLoadout->mfForMainHandAttack();
        } else {
            $damage = $this->combat->rollWeaponDamage(
                $attackerLoadout->offHandDamageMin,
                $attackerLoadout->offHandDamageMax,
            );
            $mf = $attackerLoadout->mfForOffHandAttack();
        }

        $atkFighter = $this->fighter(
            $attacker,
            $attackerFight,
            $attackerLoadout,
            $attackerStance,
            $damage,
            $mf,
        );
        $defFighter = $this->fighter(
            $defender,
            $defenderFight,
            $defenderLoadout,
            $defenderStance,
            $this->combat->rollWeaponDamage(
                $defenderLoadout->weaponDamageMin,
                $defenderLoadout->weaponDamageMax,
            ),
            $defenderLoadout->mf,
        );

        return $this->combat->calculateHit($atkFighter, $defFighter, $zone, $defendZones);
    }

    private function fighter(
        Character $character,
        Fight $fight,
        EquippedLoadout $loadout,
        StanceEnum $stance,
        int $weaponDamage,
        Mf $weaponMf,
    ): Fighter {
        $name = $character->username;

        if ($name === null) {
            $name = __('common.unnamed');
        }

        return new Fighter(
            $name,
            $character->level,
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

    private function applyAttackerStamina(Fight $fight, StanceEnum $stance, HitResult $hit): Fight
    {
        $drain = $this->combat->staminaDrainForAttacker($stance, $hit->critical, $hit->pierced);
        $fight->player_stamina = $this->combat->clampStamina(
            $fight->player_stamina - $drain,
            $fight->player_max_stamina,
        );

        return $fight;
    }

    private function applyDefenderStamina(Fight $fight, HitResult $hit): Fight
    {
        $drain = $this->combat->staminaDrainForDefender($hit->dodged);
        $fight->player_stamina = $this->combat->clampStamina(
            $fight->player_stamina - $drain,
            $fight->player_max_stamina,
        );

        return $fight;
    }

    private function enterWait(Fight $fight): Fight
    {
        $fight->step = FightStepEnum::WAIT;
        $this->clearWizard($fight);

        return $this->fights->clearTurnWatch($fight);
    }

    private function clearWizard(Fight $fight): void
    {
        $fight->player_stance = null;
        $fight->player_attack = null;
        $fight->player_attack_second = null;
        $fight->player_defend = null;
        $fight->player_defend_second = null;
        $fight->use_potion = false;
    }

    private function persistDuelLog(PvpDuel $duel, Fight $fight): void
    {
        $duel->log = $fight->log;
    }

    /**
     * @return array<string, mixed>
     */
    private function choiceFromFight(Fight $fight, bool $skipped): array
    {
        return [
            'skipped' => $skipped,
            'skip_log' => null,
            'stance' => $fight->player_stance?->value,
            'attack' => $fight->player_attack?->value,
            'attack_second' => $fight->player_attack_second?->value,
            'defend' => $fight->player_defend?->value,
            'defend_second' => $fight->player_defend_second?->value,
            'use_potion' => $fight->use_potion
                || ($fight->player_attack instanceof PlayerAttackEnum && $fight->player_attack->isPotion()),
        ];
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function writeChoice(PvpDuel $duel, int $tgId, array $choice): void
    {
        if ($duel->a_tg_id === $tgId) {
            $duel->a_choice = $choice;
        } else {
            $duel->b_choice = $choice;
        }

        $duel->save();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sideChoice(PvpDuel $duel, int $tgId): ?array
    {
        if ($duel->a_tg_id === $tgId) {
            return $duel->a_choice;
        }

        return $duel->b_choice;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function isSkipped(array $choice): bool
    {
        return array_key_exists('skipped', $choice) && $choice['skipped'] === true;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function isPotion(array $choice): bool
    {
        if (array_key_exists('use_potion', $choice) && $choice['use_potion'] === true) {
            return true;
        }

        if (! array_key_exists('attack', $choice) || ! is_string($choice['attack'])) {
            return false;
        }

        return $choice['attack'] === PlayerAttackEnum::POTION->value
            || $choice['attack'] === PlayerAttackEnum::STAMINA_POTION->value;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function stanceFromChoice(array $choice): StanceEnum
    {
        if ($this->isSkipped($choice)) {
            return StanceEnum::DEFEND;
        }

        if (array_key_exists('stance', $choice) && is_string($choice['stance'])) {
            return StanceEnum::from($choice['stance']);
        }

        return StanceEnum::DEFEND;
    }

    /**
     * @param  array<string, mixed>  $choice
     * @return list<ZoneEnum>
     */
    private function defendZones(array $choice, EquippedLoadout $loadout): array
    {
        if ($this->isSkipped($choice)) {
            return [];
        }

        $zones = [];
        $first = $this->firstDefend($choice);

        if ($first instanceof ZoneEnum) {
            $zones[] = $first;
        }

        $second = $this->secondDefend($choice);

        if ($second instanceof ZoneEnum && $loadout->blockSlots >= 2) {
            $zones[] = $second;
        }

        return $zones;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function firstDefend(array $choice): ?ZoneEnum
    {
        if (! array_key_exists('defend', $choice) || ! is_string($choice['defend'])) {
            return null;
        }

        return ZoneEnum::from($choice['defend']);
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function secondDefend(array $choice): ?ZoneEnum
    {
        if (! array_key_exists('defend_second', $choice) || ! is_string($choice['defend_second'])) {
            return null;
        }

        return ZoneEnum::from($choice['defend_second']);
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function attackZone(array $choice, bool $mainHand): ?ZoneEnum
    {
        $key = 'attack';

        if (! $mainHand) {
            $key = 'attack_second';
        }

        if (! array_key_exists($key, $choice) || ! is_string($choice[$key])) {
            return null;
        }

        if (
            $choice[$key] === PlayerAttackEnum::POTION->value
            || $choice[$key] === PlayerAttackEnum::STAMINA_POTION->value
        ) {
            return null;
        }

        return ZoneEnum::from($choice[$key]);
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function attackEnum(array $choice, bool $mainHand): ?PlayerAttackEnum
    {
        $key = 'attack';

        if (! $mainHand) {
            $key = 'attack_second';
        }

        if (! array_key_exists($key, $choice) || ! is_string($choice[$key])) {
            return null;
        }

        return PlayerAttackEnum::from($choice[$key]);
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function hasSecondAttack(array $choice, EquippedLoadout $loadout): bool
    {
        if ($loadout->attackSlots < 2) {
            return false;
        }

        return $this->attackZone($choice, false) instanceof ZoneEnum;
    }

    /**
     * @return array{
     *     skipped: bool,
     *     player_hp_delta: int,
     *     enemy_hp_delta: int,
     *     player_stance: string|null,
     *     player_attack: string|null,
     *     player_attack_second: string|null,
     *     player_defend: string|null,
     *     player_defend_second: string|null,
     *     enemy_stance: string,
     *     enemy_attack: string,
     *     enemy_attack_second: string|null,
     *     enemy_defend: string,
     *     enemy_defend_second: string|null
     * }
     */
    private function lastRound(
        bool $skip,
        int $playerHpBefore,
        int $playerHpAfter,
        int $enemyHpBefore,
        int $enemyHpAfter,
        StanceEnum $playerStance,
        ?PlayerAttackEnum $playerAttack,
        ?PlayerAttackEnum $playerAttackSecond,
        ?ZoneEnum $playerDefend,
        ?ZoneEnum $playerDefendSecond,
        StanceEnum $enemyStance,
        ZoneEnum $enemyAtk,
        ?ZoneEnum $enemyAtkSecond,
        ZoneEnum $enemyDef,
        ?ZoneEnum $enemyDefSecond,
    ): array {
        return [
            'skipped' => $skip,
            'player_hp_delta' => $playerHpAfter - $playerHpBefore,
            'enemy_hp_delta' => $enemyHpAfter - $enemyHpBefore,
            'player_stance' => $skip ? null : $playerStance->value,
            'player_attack' => $skip ? null : $playerAttack?->value,
            'player_attack_second' => $skip ? null : $playerAttackSecond?->value,
            'player_defend' => $skip ? null : $playerDefend?->value,
            'player_defend_second' => $skip ? null : $playerDefendSecond?->value,
            'enemy_stance' => $enemyStance->value,
            'enemy_attack' => $enemyAtk->value,
            'enemy_attack_second' => $enemyAtkSecond?->value,
            'enemy_defend' => $enemyDef->value,
            'enemy_defend_second' => $enemyDefSecond?->value,
        ];
    }

    private function loadoutAfterDrop(Character $character): EquippedLoadout
    {
        $character = $this->loadout->dropUnmetEquipped($character);

        return $this->loadout->forCharacter($character);
    }
}
