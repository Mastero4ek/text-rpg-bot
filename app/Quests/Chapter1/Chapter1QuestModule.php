<?php

declare(strict_types=1);

namespace App\Quests\Chapter1;

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Quests\BuyerQuest;
use App\Quests\QuestChapterModuleContract;
use App\Support\ActionResult;
use InvalidArgumentException;
use RuntimeException;

final class Chapter1QuestModule implements QuestChapterModuleContract
{
    public function __construct(
        private readonly BuyerQuest $buyerQuest,
        private readonly Chapter1ScreenPanelPusher $panelPusher,
        private readonly Chapter1ScreenProjector $projector,
        private readonly Chapter1ScreenRewardGranter $rewardGranter,
    ) {}

    public function chapter(): QuestChapterEnum
    {
        return QuestChapterEnum::Chapter1;
    }

    public function sectionCount(): int
    {
        return 7;
    }

    /**
     * @return array<string, string>
     */
    public function screenOptionsForSection(int $section): array
    {
        return Chapter1ScreenEnum::optionsForSection($section);
    }

    public function detect(Character $character): ?Chapter1ScreenEnum
    {
        if (! $character->progress_step->canPlayCity()) {
            return match ($character->progress_step) {
                ProgressStepEnum::SPLASH => Chapter1ScreenEnum::RegistrationSplash,
                ProgressStepEnum::SET_NICK => Chapter1ScreenEnum::RegistrationSetNick,
                ProgressStepEnum::SET_SKIN => Chapter1ScreenEnum::RegistrationSetSkin,
                ProgressStepEnum::SET_CITY => Chapter1ScreenEnum::RegistrationSetCity,
                default => Chapter1ScreenEnum::RegistrationSplash,
            };
        }

        if ($character->quest_phase === QuestPhaseEnum::Done) {
            return Chapter1ScreenEnum::Chapter1Done;
        }

        if ($character->bruiser_knuckle_pending) {
            return Chapter1ScreenEnum::BruiserKnucklePending;
        }

        if ($character->healer_rat_loot_pending) {
            return Chapter1ScreenEnum::HealerRatLoot;
        }

        if ($character->healer_debt_open) {
            return Chapter1ScreenEnum::HealerDebt;
        }

        if ($character->bruiser_contract_active) {
            return Chapter1ScreenEnum::BruiserArrive;
        }

        if ($character->quest_phase === QuestPhaseEnum::Bruiser && $character->buyer_at_tavern) {
            if ($this->buyerQuest->awaitsTurnIn($character)) {
                return Chapter1ScreenEnum::BuyerTurnIn;
            }

            return Chapter1ScreenEnum::BruiserReadyForPurse;
        }

        if ($character->quest_phase === QuestPhaseEnum::Buyer) {
            return Chapter1ScreenEnum::BuyerMeet;
        }

        if ($character->quest_phase === QuestPhaseEnum::Blacksmith) {
            return Chapter1ScreenEnum::BlacksmithMeet;
        }

        if ($character->quest_phase === QuestPhaseEnum::Healer) {
            if ($character->healer_meet_open) {
                return Chapter1ScreenEnum::HealerPotionLock;
            }

            return Chapter1ScreenEnum::HealerMeet;
        }

        if ($character->quest_phase === QuestPhaseEnum::Overseer) {
            if ($character->overseer_train_step === OverseerTrainStepEnum::Stats) {
                return Chapter1ScreenEnum::OverseerStats;
            }

            if ($character->overseer_train_step === OverseerTrainStepEnum::Fight) {
                return Chapter1ScreenEnum::OverseerAwaitFight;
            }

            if ($character->overseer_train_step === OverseerTrainStepEnum::Intro) {
                return Chapter1ScreenEnum::OverseerIntro;
            }

            if ($character->overseer_at_tavern) {
                return Chapter1ScreenEnum::OverseerTavern;
            }

            return Chapter1ScreenEnum::OverseerGates;
        }

        return null;
    }

    public function screenFrom(string $screenValue): Chapter1ScreenEnum
    {
        $screen = Chapter1ScreenEnum::tryFrom($screenValue);

        if (! $screen instanceof Chapter1ScreenEnum) {
            throw new InvalidArgumentException('Unknown Chapter1 screen: ' . $screenValue);
        }

        return $screen;
    }

    public function applySnapshot(Character $character, mixed $screen): void
    {
        if (! $screen instanceof Chapter1ScreenEnum) {
            throw new RuntimeException('Chapter1QuestModule expects Chapter1ScreenEnum.');
        }

        $from = $this->detect($character);
        $this->projector->apply($character, $screen);
        $this->rewardGranter->sync($character, $from, $screen);
    }

    public function pushPanel(Character $character, mixed $screen): ActionResult
    {
        if (! $screen instanceof Chapter1ScreenEnum) {
            throw new RuntimeException('Chapter1QuestModule expects Chapter1ScreenEnum.');
        }

        return $this->panelPusher->push($character, $screen);
    }

    /**
     * @return list<string>
     */
    public function ownedStateKeys(): array
    {
        return [
            'quest_chapter',
            'progress_step',
            'quest_phase',
            'unlock_portal',
            'unlock_ruins',
            'unlock_forest',
            'unlock_board',
            'unlock_arena',
            'city_unlocked_full',
            'overseer_trained',
            'overseer_at_tavern',
            'overseer_train_step',
            'healer_at_tavern',
            'healer_meet_open',
            'blacksmith_at_tavern',
            'buyer_at_tavern',
            'bruiser_contract_active',
            'bruiser_knuckle_pending',
            'healer_debt_open',
            'healer_rat_loot_pending',
        ];
    }
}
