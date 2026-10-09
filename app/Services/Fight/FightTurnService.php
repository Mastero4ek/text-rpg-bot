<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagService;
use Illuminate\Support\Facades\DB;

final class FightTurnService
{
    public function __construct(
        private readonly FightService $fights,
        private readonly LoadoutService $loadout,
        private readonly BagService $bag,
    ) {}

    public function commitStance(int $tgId, StanceEnum $stance): FightTurnCommit
    {
        return DB::transaction(function () use ($tgId, $stance): FightTurnCommit {
            $player = Character::query()->find($tgId);

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return FightTurnCommit::noop();
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step !== FightStepEnum::STANCE) {
                return FightTurnCommit::noop();
            }

            $fight->use_potion = false;
            $fight->player_stance = $stance;
            $fight->step = FightStepEnum::ATTACK;
            $this->fights->save($fight);

            return FightTurnCommit::attack($player, $fight);
        });
    }

    public function commitAttack(int $tgId, string $choice): FightTurnCommit
    {
        return DB::transaction(function () use ($tgId, $choice): FightTurnCommit {
            $player = Character::query()->find($tgId);

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return FightTurnCommit::noop();
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step === FightStepEnum::ATTACK_SECOND) {
                if ($choice === 'POTION' || $choice === 'STAMINA_POTION') {
                    return FightTurnCommit::noop();
                }

                $fight->player_attack_second = PlayerAttackEnum::from($choice);
                $fight->step = FightStepEnum::DEFEND;
                $this->fights->save($fight);

                return FightTurnCommit::defend($player, $fight);
            }

            if ($fight->step !== FightStepEnum::ATTACK) {
                return FightTurnCommit::noop();
            }

            if ($choice === 'POTION' || $choice === 'STAMINA_POTION') {
                $attack = PlayerAttackEnum::from($choice);

                if ($fight->tutorial || ! $this->canUsePotionAttack($player, $attack)) {
                    return FightTurnCommit::potionDenied();
                }

                $fight->use_potion = true;
                $fight->player_attack = $attack;
                $fight->player_attack_second = null;
                $fight->step = FightStepEnum::DEFEND;
                $this->fights->save($fight);

                return FightTurnCommit::defend($player, $fight);
            }

            $fight->use_potion = false;
            $fight->player_attack = PlayerAttackEnum::from($choice);
            $fight->player_attack_second = null;

            $loadout = $this->loadout->forCharacter($player);

            if ($loadout->attackSlots >= 2) {
                $fight->step = FightStepEnum::ATTACK_SECOND;
                $this->fights->save($fight);

                return FightTurnCommit::attackSecond($player, $fight);
            }

            $fight->step = FightStepEnum::DEFEND;
            $this->fights->save($fight);

            return FightTurnCommit::defend($player, $fight);
        });
    }

    public function commitDefend(int $tgId, ZoneEnum $zone): FightTurnCommit
    {
        return DB::transaction(function () use ($tgId, $zone): FightTurnCommit {
            $player = Character::query()->find($tgId);

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return FightTurnCommit::noop();
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step === FightStepEnum::DEFEND) {
                $fight->player_defend = $zone;
                $fight->player_defend_second = null;

                $loadout = $this->loadout->forCharacter($player);

                if ($loadout->blockSlots >= 2) {
                    $fight->step = FightStepEnum::DEFEND_SECOND;
                    $this->fights->save($fight);

                    return FightTurnCommit::defendSecond($player, $fight, $zone);
                }

                $this->fights->save($fight);

                return FightTurnCommit::runRound($player);
            }

            if ($fight->step !== FightStepEnum::DEFEND_SECOND) {
                return FightTurnCommit::noop();
            }

            if ($fight->player_defend === $zone) {
                return FightTurnCommit::noop();
            }

            $fight->player_defend_second = $zone;
            $this->fights->save($fight);

            return FightTurnCommit::runRound($player);
        });
    }

    /**
     * @return list<PlayerAttackEnum>
     */
    public function availablePotionAttacks(Character $player, bool $tutorial): array
    {
        if ($tutorial) {
            return [];
        }

        $attacks = [];

        if ($this->bag->potionCountByProfile($player->tg_id, ProfileEnum::HEAL) > 0) {
            $attacks[] = PlayerAttackEnum::POTION;
        }

        if ($this->bag->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA) > 0) {
            $attacks[] = PlayerAttackEnum::STAMINA_POTION;
        }

        return $attacks;
    }

    private function canUsePotionAttack(Character $player, PlayerAttackEnum $attack): bool
    {
        if ($attack === PlayerAttackEnum::POTION) {
            return $this->bag->potionCountByProfile($player->tg_id, ProfileEnum::HEAL) > 0;
        }

        if ($attack === PlayerAttackEnum::STAMINA_POTION) {
            return $this->bag->potionCountByProfile($player->tg_id, ProfileEnum::STAMINA) > 0;
        }

        return false;
    }
}
