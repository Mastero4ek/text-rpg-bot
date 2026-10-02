<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\FightPlayerAttackEnum;
use App\Enums\FightStepEnum;
use App\Enums\OnboardingStepEnum;
use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\Character\CharacterService;
use App\Services\Combat\CombatService;
use App\Services\Fight\FightRoundService;
use App\Services\Fight\FightService;
use App\Services\Game\GameConfig;
use App\Services\Onboarding\OnboardingService;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CombatHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CombatService $combat,
        private readonly FightService $fights,
        private readonly FightRoundService $rounds,
        private readonly OnboardingService $onboarding,
        private readonly GameConfig $config,
        private readonly FightStatusFormatter $fightStatus,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        if (preg_match('/^fight:stance:(ATTACK|DEFEND)$/', $data, $m) === 1) {
            $this->stance($update, $responder, StanceEnum::from($m[1]));

            return;
        }

        if (preg_match('/^fight:atk:(HEAD|CHEST|BELLY|LEGS|POTION)$/', $data, $m) === 1) {
            $this->attack($update, $responder, $m[1]);

            return;
        }

        if (preg_match('/^fight:def:(HEAD|CHEST|BELLY|LEGS)$/', $data, $m) === 1) {
            $this->defend($update, $responder, ZoneEnum::from($m[1]));

            return;
        }

        if ($data === 'menu:fight') {
            $this->pickEnemy($update, $responder);

            return;
        }

        if (preg_match('/^fight:start:(soldier|mob)$/', $data, $m) === 1) {
            $this->startFight($update, $responder, $m[1]);
        }
    }

    private function stance(TelegramUpdate $update, TelegramResponder $responder, StanceEnum $stance): void
    {
        $saved = DB::transaction(function () use ($update, $stance): ?array {
            $player = Character::query()->find($update->userId());

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return null;
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step !== FightStepEnum::STANCE) {
                return null;
            }

            $fight->use_potion = false;
            $fight->player_stance = $stance;
            $fight->step = FightStepEnum::ATTACK;
            $this->fights->save($fight);

            return ['player' => $player, 'fight' => $fight];
        });

        if ($saved === null) {
            return;
        }

        /** @var Character $player */
        $player = $saved['player'];
        /** @var Fight $fight */
        $fight = $saved['fight'];

        if ($player->potions > 0 && ! $fight->tutorial) {
            $keyboard = TelegramKeyboards::attackWithPotion();
        } else {
            $keyboard = TelegramKeyboards::attackWithoutPotion();
        }

        $responder->edit(
            $this->fightStatus->format($fight, $player->username) . __('combat.pick_attack'),
            $keyboard,
        );
    }

    private function attack(TelegramUpdate $update, TelegramResponder $responder, string $choice): void
    {
        $saved = DB::transaction(function () use ($update, $choice): ?array {
            $player = Character::query()->find($update->userId());

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return null;
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step !== FightStepEnum::ATTACK) {
                return null;
            }

            if ($choice === 'POTION') {
                if ($fight->tutorial || $player->potions <= 0) {
                    return ['kind' => 'potion_denied'];
                }

                $fight->use_potion = true;
                $fight->player_attack = FightPlayerAttackEnum::POTION;
            } else {
                $fight->use_potion = false;
                $fight->player_attack = FightPlayerAttackEnum::from($choice);
            }

            $fight->step = FightStepEnum::DEFEND;
            $this->fights->save($fight);

            return ['kind' => 'ok', 'player' => $player, 'fight' => $fight];
        });

        if ($saved === null) {
            return;
        }

        if ($saved['kind'] === 'potion_denied') {
            $responder->reply(__('errors.potion_unavailable'), null);

            return;
        }

        /** @var Character $player */
        $player = $saved['player'];
        /** @var Fight $fight */
        $fight = $saved['fight'];

        if ($fight->use_potion) {
            $prompt = __('combat.potion_then_defend');
        } else {
            $prompt = __('combat.pick_defend');
        }

        $responder->edit(
            $this->fightStatus->format($fight, $player->username) . $prompt,
            TelegramKeyboards::defend(),
        );
    }

    private function defend(TelegramUpdate $update, TelegramResponder $responder, ZoneEnum $zone): void
    {
        $player = DB::transaction(function () use ($update, $zone): ?Character {
            $player = Character::query()->find($update->userId());

            if ($player === null || ! $this->fights->exists($player->tg_id)) {
                return null;
            }

            $fight = $this->fights->findByTgId($player->tg_id);

            if ($fight->step !== FightStepEnum::DEFEND) {
                return null;
            }

            $fight->player_defend = $zone;
            $this->fights->save($fight);

            return $player;
        });

        if ($player === null) {
            return;
        }

        $outcome = $this->rounds->resolve($player);

        if ($outcome->kind === 'missing' || ! $outcome->character instanceof Character || ! $outcome->fight instanceof Fight) {
            return;
        }

        if ($outcome->kind === 'win') {
            $this->endFight($responder, $outcome->character, $outcome->fight, true);

            return;
        }

        if ($outcome->kind === 'lose') {
            $this->endFight($responder, $outcome->character, $outcome->fight, false);

            return;
        }

        $responder->edit(
            $this->fightStatus->format($outcome->fight, $outcome->character->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
    }

    private function pickEnemy(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        $responder->edit(__('combat.pick_enemy'), TelegramKeyboards::fightPick($player->level));
    }

    private function startFight(TelegramUpdate $update, TelegramResponder $responder, string $kind): void
    {
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        if ($kind === 'soldier') {
            $enemy = $this->combat->makeWoodenSoldier();
        } else {
            $enemy = $this->combat->makeMob($player->level);
        }

        $fight = $this->fights->createTraining($player, $enemy);
        $responder->reply(
            $this->fightStatus->format($fight, $player->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
    }

    private function endFight(
        TelegramResponder $responder,
        Character $player,
        Fight $fight,
        bool $won,
    ): void {
        $text = $this->fightStatus->format($fight, $player->username);

        if ($fight->tutorial) {
            if ($won) {
                $player = $this->onboarding->onTutorialWin($player);
                $this->fights->clear($player->tg_id);
                $reward = $this->tutorialReward();
                $responder->edit(
                    $text . __('onboarding.tutorial_win', [
                        'exp' => $reward['exp'],
                        'gold' => $reward['gold'],
                    ]),
                    null,
                );
                $responder->reply(
                    $this->onboarding->statsQuestText($player),
                    TelegramKeyboards::statsQuest($player),
                );

                return;
            }

            $player = $this->onboarding->onTutorialLose($player);
            $this->fights->clear($player->tg_id);
            $responder->edit($text . __('onboarding.tutorial_lose'), null);
            $responder->reply($this->onboarding->introText(), TelegramKeyboards::intro());
            $player->onboarding_step = OnboardingStepEnum::INTRO;
            $player->save();

            return;
        }

        if ($won) {
            $enemy = $this->fights->enemy($fight);
            $reward = $this->combat->pveRewards($enemy->level);
            $this->characters->addExpGold($player, $reward['exp'], $reward['gold']);
            $player->current_hp = max(1, $fight->player_hp);
            $player->save();
            $this->fights->clear($player->tg_id);
            $responder->edit(
                $text . __('combat.win', [
                    'exp' => $reward['exp'],
                    'gold' => $reward['gold'],
                ]),
                TelegramKeyboards::mainMenu(),
            );

            return;
        }

        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->save();
        $this->fights->clear($player->tg_id);
        $responder->edit($text . __('combat.lose'), TelegramKeyboards::mainMenu());
    }

    private function requireDone(TelegramUpdate $update, TelegramResponder $responder): ?Character
    {
        if (! $update->hasFrom()) {
            return null;
        }

        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return null;
        }

        $player = $this->characters->applyRegen($player);

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);

            return null;
        }

        return $player;
    }

    /**
     * @return array{exp: int, gold: int}
     */
    private function tutorialReward(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        $row = $onboarding['rewards']['tutorialWin'] ?? null;

        if (! is_array($row) || ! is_int($row['exp']) || ! is_int($row['gold'])) {
            throw new RuntimeException('tutorialWin reward missing.');
        }

        return ['exp' => $row['exp'], 'gold' => $row['gold']];
    }
}
