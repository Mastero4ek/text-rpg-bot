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
use App\Support\PotionDef;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\DB;

final class FightTurnService
{
    public function __construct(
        private readonly FightService $fights,
        private readonly LoadoutService $loadout,
        private readonly BagService $bag,
        private readonly CharacterService $characters,
    ) {}

    public function commitAttack(int $tgId, string $choice): FightTurnCommit
    {
        return DB::transaction(function () use ($tgId, $choice): FightTurnCommit {
            $player = Character::query()->find($tgId);

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return FightTurnCommit::noop();
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($choice === 'POTION' || $choice === 'STAMINA_POTION') {
                return $this->applyPotionChoice($player, $fight, PlayerAttackEnum::from($choice));
            }

            if ($fight->step === FightStepEnum::ATTACK_SECOND) {
                $fight->player_attack_second = PlayerAttackEnum::from($choice);
                $fight->step = FightStepEnum::DEFEND;
                $this->fights->save($fight);

                return FightTurnCommit::defend($player, $fight);
            }

            if ($fight->step !== FightStepEnum::ATTACK) {
                return FightTurnCommit::noop();
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

    private function applyPotionChoice(
        Character $player,
        Fight $fight,
        PlayerAttackEnum $attack,
    ): FightTurnCommit {
        if ($fight->step !== FightStepEnum::STANCE && $fight->step !== FightStepEnum::ATTACK) {
            return FightTurnCommit::noop();
        }

        if ($fight->tutorial || ! $this->canUsePotionAttack($player, $attack)) {
            return FightTurnCommit::potionDenied($player, $fight);
        }

        if ($attack === PlayerAttackEnum::STAMINA_POTION) {
            $profile = ProfileEnum::STAMINA;
        } else {
            $profile = ProfileEnum::HEAL;
        }

        $consumed = $this->bag->consumePotion($player->tg_id, $profile);

        if (! $consumed->ok || ! $consumed->potion instanceof PotionDef) {
            return FightTurnCommit::potionDenied($player, $fight);
        }

        $heal = $consumed->potion->effectValue;

        if ($player->username === null) {
            $drinkName = __('common.you');
        } else {
            $drinkName = TelegramHtml::escape($player->username);
        }

        $log = $fight->log;

        if ($profile === ProfileEnum::STAMINA) {
            $fight->player_stamina = $this->characters->clampStamina(
                $fight->player_stamina + $heal,
                $fight->player_max_stamina,
            );
            $log[] = __('combat.drink_stamina_potion', [
                'name' => $drinkName,
                'heal' => $heal,
            ]);
        } else {
            $fight->player_hp = $this->characters->clampHp(
                $fight->player_hp + $heal,
                $fight->player_max_hp,
            );
            $log[] = __('combat.drink_potion', [
                'name' => $drinkName,
                'heal' => $heal,
            ]);
        }

        $fight->log = $log;
        $fight->use_potion = false;
        $fight->player_stance = StanceEnum::DEFEND;
        $fight->player_attack = $attack;
        $fight->player_attack_second = null;
        $fight->step = FightStepEnum::DEFEND;
        $this->fights->save($fight);

        return FightTurnCommit::defend($player, $fight);
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
