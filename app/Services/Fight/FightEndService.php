<?php

declare(strict_types=1);

namespace App\Services\Fight;

use App\Actions\Backpack\BackpackApplyFightWearAction;
use App\Actions\Bag\BagGemBreakOnLoseAction;
use App\Actions\Enemy\EnemyApplyWinLootAction;
use App\Actions\Fight\FightClearAction;
use App\Enums\Fight\FightEndUiEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Services\CharacterService;
use App\Services\GameConfig;
use App\Services\Onboarding\OnboardingService;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class FightEndService
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly FightService $fights,
        private readonly FightClearAction $clearFight,
        private readonly BackpackApplyFightWearAction $fightWear,
        private readonly BagGemBreakOnLoseAction $breakGems,
        private readonly EnemyApplyWinLootAction $winLoot,
        private readonly OnboardingService $onboarding,
        private readonly GameConfig $config,
        private readonly FightStatusFormatter $fightStatus,
    ) {}

    public function finishWin(Character $player, Fight $fight): FightEndResult
    {
        $text = $this->fightStatus->format($fight, $player);

        if ($fight->tutorial) {
            return $this->tutorialWin($player, $text);
        }

        if ($fight->hall) {
            return $this->hallWin($player, $fight, $text);
        }

        return $this->trainingWin($player, $fight, $text);
    }

    public function finishFlee(Character $player, Fight $fight): FightEndResult
    {
        $text = $this->fightStatus->format($fight, $player);

        if ($fight->hall) {
            return $this->hallFlee($player, $fight, $text);
        }

        return $this->trainingFlee($player, $fight, $text);
    }

    public function finishLose(Character $player, Fight $fight): FightEndResult
    {
        $text = $this->fightStatus->format($fight, $player);

        if ($fight->tutorial) {
            return $this->tutorialLose($player, $text);
        }

        if ($fight->hall) {
            return $this->hallLose($player, $text);
        }

        return $this->trainingLose($player, $fight, $text);
    }

    private function tutorialWin(Character $player, string $text): FightEndResult
    {
        $player = $this->onboarding->onTutorialWin($player);
        $this->clearFight->handle($player->tg_id);
        $reward = $this->tutorialReward();

        return new FightEndResult(
            $player,
            $text . __('onboarding.tutorial_win', [
                'exp' => $reward['exp'],
                'silver' => $reward['silver'],
            ]),
            FightEndUiEnum::None,
            $this->onboarding->statsQuestText($player),
            FightEndUiEnum::StatsQuest,
        );
    }

    private function tutorialLose(Character $player, string $text): FightEndResult
    {
        $player = $this->onboarding->onTutorialLose($player);
        $this->clearFight->handle($player->tg_id);
        $player = $this->onboarding->resetToIntro($player);

        return new FightEndResult(
            $player,
            $text . __('onboarding.tutorial_lose'),
            FightEndUiEnum::None,
            $this->onboarding->introText(),
            FightEndUiEnum::Intro,
        );
    }

    private function hallWin(Character $player, Fight $fight, string $text): FightEndResult
    {
        $reward = $this->config->trainingReward();
        $this->characters->addExpSilver($player, $reward['exp'], $reward['silver']);
        $player = $this->characters->findByTgId($player->tg_id);
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.win', [
                'exp' => $reward['exp'],
                'silver' => $reward['silver'],
            ]),
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
        );
    }

    private function hallFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.flee'),
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
        );
    }

    private function hallLose(Character $player, string $text): FightEndResult
    {
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.lose'),
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
        );
    }

    private function trainingFlee(Character $player, Fight $fight, string $text): FightEndResult
    {
        $broken = $this->fightWear->handleAfterLose($player, $fight->pierce_count);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken);
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.flee') . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
        );
    }

    private function trainingWin(Character $player, Fight $fight, string $text): FightEndResult
    {
        $broken = $this->fightWear->handleAfterWin($player, $fight->pierce_count);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken);
        $enemy = $this->fights->enemy($fight);
        $loot = $this->winLoot->handle($player, $enemy);
        $player = $this->characters->findByTgId($player->tg_id);
        $player->current_hp = max(1, min($fight->player_hp, $this->characters->maxHp($player)));
        $player->current_stamina = $this->characters->clampStamina(
            $fight->player_stamina,
            $this->characters->maxStamina($player),
        );
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.win', [
                'exp' => $loot['exp'],
                'silver' => $loot['silver'],
            ]) . $this->dropSuffix($loot['drop_names']) . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
        );
    }

    private function trainingLose(Character $player, Fight $fight, string $text): FightEndResult
    {
        $broken = $this->fightWear->handleAfterLose($player, $fight->pierce_count);
        $gemBroken = $this->breakGems->handle($player);
        $player = $this->characters->findByTgId($player->tg_id);
        $brokeSuffix = $this->brokenGearSuffix($broken) . $this->brokenGemsSuffix($gemBroken);
        $player->current_hp = 0;
        $player->last_hp_update = now();
        $player->current_stamina = 0;
        $player->last_stamina_update = now();
        $player->save();
        $this->clearFight->handle($player->tg_id);

        return new FightEndResult(
            $player,
            $text . __('combat.lose') . $brokeSuffix,
            FightEndUiEnum::BackToCity,
            null,
            FightEndUiEnum::None,
        );
    }

    /**
     * @param  list<string>  $names
     */
    private function dropSuffix(array $names): string
    {
        if ($names === []) {
            return '';
        }

        return __('combat.drop', ['names' => TelegramHtml::escapeJoin($names, ', ')]);
    }

    /**
     * @param  list<string>  $broken
     */
    private function brokenGearSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gear_broke', ['names' => TelegramHtml::escapeJoin($broken, ', ')]);
    }

    /**
     * @param  list<string>  $broken
     */
    private function brokenGemsSuffix(array $broken): string
    {
        if ($broken === []) {
            return '';
        }

        return __('combat.gems_broke', ['names' => TelegramHtml::escapeJoin($broken, ', ')]);
    }

    /**
     * @return array{exp: int, silver: int}
     */
    private function tutorialReward(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        $row = $onboarding['rewards']['tutorialQuest'] ?? null;

        if (! is_array($row) || ! is_int($row['exp']) || ! is_int($row['silver'])) {
            throw new RuntimeException('tutorialQuest reward missing.');
        }

        return ['exp' => $row['exp'], 'silver' => $row['silver']];
    }
}
