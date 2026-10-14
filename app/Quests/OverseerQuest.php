<?php

declare(strict_types=1);

namespace App\Quests;

use App\Actions\Overseer\OverseerAcceptChallengeAction;
use App\Actions\Overseer\OverseerAdvanceToStatsAction;
use App\Actions\Overseer\OverseerAgreeTrainingAction;
use App\Actions\Overseer\OverseerCompleteAction;
use App\Actions\Overseer\OverseerResetTrainToIntroAction;
use App\Actions\Overseer\OverseerSkipGatesAction;
use App\Actions\Overseer\OverseerStartTrainingAction;
use App\Actions\Overseer\OverseerSubmitStatsAction;
use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\Fight;
use App\Services\Fight\FightService;
use App\Support\ActionResult;
use App\Support\Telegram\TelegramHtml;
use RuntimeException;

final class OverseerQuest
{
    public function __construct(
        private readonly OverseerAcceptChallengeAction $acceptChallenge,
        private readonly OverseerSkipGatesAction $skipGates,
        private readonly OverseerAgreeTrainingAction $agreeTraining,
        private readonly OverseerStartTrainingAction $startTraining,
        private readonly OverseerAdvanceToStatsAction $advanceToStats,
        private readonly OverseerResetTrainToIntroAction $resetToIntro,
        private readonly OverseerSubmitStatsAction $submitStats,
        private readonly OverseerCompleteAction $complete,
        private readonly FightService $fights,
    ) {}

    public function accept(Character $character): ActionResult
    {
        return $this->acceptChallenge->handle($character);
    }

    public function advanceAfterWin(Character $character): ActionResult
    {
        return $this->advanceToStats->handle($character);
    }

    public function agreeFromTavern(Character $character): ActionResult
    {
        return $this->agreeTraining->handle($character);
    }

    public function awaitsMeet(Character $character): bool
    {
        return $character->progress_step === ProgressStepEnum::ARRIVED
            && $character->quest_phase === QuestPhaseEnum::Overseer
            && ! $character->overseer_trained
            && ! $character->overseer_at_tavern
            && ! $character->overseer_train_step instanceof OverseerTrainStepEnum;
    }

    public function completeAfterStats(Character $character): ActionResult
    {
        $submit = $this->submitStats->handle($character);

        if (! $submit->ok || ! $submit->character instanceof Character) {
            return $submit;
        }

        return $this->complete->handle($submit->character);
    }

    public function completeSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.overseer.complete', $character);
    }

    public function introSpeech(): string
    {
        return __('telegram.quest.overseer.intro', [
            'seconds' => $this->fights->turnTimeoutSeconds(),
        ]);
    }

    public function isTrainLocked(Character $character): bool
    {
        return $character->overseer_train_step instanceof OverseerTrainStepEnum
            && ! $character->overseer_trained;
    }

    public function meetSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.overseer.meet', $character);
    }

    public function panelImagePath(Character $character): string
    {
        if ($character->overseer_at_tavern) {
            $path = config('bot.overseer_tavern_image');
        } else {
            $path = config('bot.overseer_image');
        }

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('Overseer panel image missing.');
        }

        return $path;
    }

    public function resetAfterLose(Character $character): ActionResult
    {
        return $this->resetToIntro->handle($character);
    }

    public function skip(Character $character): ActionResult
    {
        return $this->skipGates->handle($character);
    }

    public function startFight(Character $character): ActionResult
    {
        return $this->startTraining->handle($character);
    }

    public function statsSpeech(Character $character): string
    {
        return __('telegram.quest.overseer.stats', [
            'points' => $character->stat_points,
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
        ]);
    }

    public function tavernSpeech(Character $character): string
    {
        return $this->speech('telegram.quest.overseer.tavern', $character);
    }

    public function tutorialFight(Character $character): Fight
    {
        return $this->fights->findByTgId($character->tg_id);
    }

    private function cityName(Character $character): string
    {
        $character->loadMissing('city');

        if (! $character->city instanceof City) {
            throw new RuntimeException('Overseer quest city missing.');
        }

        return TelegramHtml::escape($character->city->name);
    }

    private function speech(string $key, Character $character): string
    {
        return __($key, ['city' => $this->cityName($character)]);
    }
}
