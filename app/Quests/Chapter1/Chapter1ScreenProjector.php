<?php

declare(strict_types=1);

namespace App\Quests\Chapter1;

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\OverseerTrainStepEnum;
use App\Enums\Quest\QuestChapterEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Enums\SkinEnum;
use App\Models\Character\Character;

final class Chapter1ScreenProjector
{
    public function apply(Character $character, Chapter1ScreenEnum $screen): void
    {
        $this->clearOwnedState($character);
        $character->quest_chapter = QuestChapterEnum::Chapter1;

        match ($screen) {
            Chapter1ScreenEnum::RegistrationSplash => $this->applyRegistration($character, ProgressStepEnum::SPLASH),
            Chapter1ScreenEnum::RegistrationSetNick => $this->applyRegistration($character, ProgressStepEnum::SET_NICK),
            Chapter1ScreenEnum::RegistrationSetSkin => $this->applyRegistration($character, ProgressStepEnum::SET_SKIN),
            Chapter1ScreenEnum::RegistrationSetCity => $this->applyRegistration($character, ProgressStepEnum::SET_CITY),
            Chapter1ScreenEnum::OverseerGates => $this->applyOverseerGates($character),
            Chapter1ScreenEnum::OverseerTavern => $this->applyOverseerTavern($character),
            Chapter1ScreenEnum::OverseerIntro => $this->applyOverseerTrain($character, OverseerTrainStepEnum::Intro),
            Chapter1ScreenEnum::OverseerAwaitFight => $this->applyOverseerTrain($character, OverseerTrainStepEnum::Fight),
            Chapter1ScreenEnum::OverseerStats => $this->applyOverseerTrain($character, OverseerTrainStepEnum::Stats),
            Chapter1ScreenEnum::HealerMeet => $this->applyHealerMeet($character),
            Chapter1ScreenEnum::HealerPotionLock => $this->applyHealerPotionLock($character),
            Chapter1ScreenEnum::BlacksmithMeet => $this->applyBlacksmithMeet($character),
            Chapter1ScreenEnum::BuyerMeet => $this->applyBuyerMeet($character),
            Chapter1ScreenEnum::BuyerTurnIn => $this->applyBuyerTurnIn($character),
            Chapter1ScreenEnum::BruiserArrive => $this->applyBruiserArrive($character),
            Chapter1ScreenEnum::HealerDebt => $this->applyHealerDebt($character),
            Chapter1ScreenEnum::HealerRatLoot => $this->applyHealerRatLoot($character),
            Chapter1ScreenEnum::BruiserKnucklePending => $this->applyBruiserKnucklePending($character),
            Chapter1ScreenEnum::BruiserReadyForPurse => $this->applyBruiserReadyForPurse($character),
            Chapter1ScreenEnum::Chapter1Done => $this->applyChapter1Done($character),
        };
    }

    public function writeAfterOverseerComplete(Character $character): void
    {
        $character->progress_step = ProgressStepEnum::DONE;
        $character->quest_phase = QuestPhaseEnum::Healer;
        $character->overseer_trained = true;
        $character->overseer_at_tavern = true;
        $character->overseer_train_step = null;
        $character->unlock_ruins = true;
        $character->healer_meet_open = false;
    }

    public function writeAfterHealerComplete(Character $character): void
    {
        $character->healer_at_tavern = true;
        $character->healer_meet_open = false;
        $character->unlock_forest = true;
        $character->quest_phase = QuestPhaseEnum::Blacksmith;
    }

    public function writeAfterBlacksmithComplete(Character $character): void
    {
        $character->blacksmith_at_tavern = true;
        $character->unlock_board = true;
        $character->quest_phase = QuestPhaseEnum::Buyer;
    }

    public function writeAfterBruiserCompleteReady(Character $character): void
    {
        $character->bruiser_contract_active = false;
        $character->bruiser_knuckle_pending = false;
        $character->healer_debt_open = false;
        $character->healer_rat_loot_pending = false;
    }

    public function writeAfterBruiserCompleteKnucklePending(Character $character): void
    {
        $character->bruiser_contract_active = false;
        $character->bruiser_knuckle_pending = true;
        $character->healer_debt_open = false;
        $character->healer_rat_loot_pending = false;
    }

    private function clearOwnedState(Character $character): void
    {
        $character->quest_phase = QuestPhaseEnum::Overseer;
        $character->unlock_portal = false;
        $character->unlock_ruins = false;
        $character->unlock_forest = false;
        $character->unlock_board = false;
        $character->unlock_arena = false;
        $character->city_unlocked_full = false;
        $character->overseer_trained = false;
        $character->overseer_at_tavern = false;
        $character->overseer_train_step = null;
        $character->healer_at_tavern = false;
        $character->healer_meet_open = false;
        $character->blacksmith_at_tavern = false;
        $character->buyer_at_tavern = false;
        $character->bruiser_contract_active = false;
        $character->bruiser_knuckle_pending = false;
        $character->healer_debt_open = false;
        $character->healer_rat_loot_pending = false;
    }

    private function applyRegistration(Character $character, ProgressStepEnum $step): void
    {
        $character->progress_step = $step;
        $character->quest_phase = QuestPhaseEnum::Overseer;

        if (
            in_array($step, [ProgressStepEnum::SPLASH, ProgressStepEnum::SET_NICK, ProgressStepEnum::SET_SKIN], true)
        ) {
            $character->skin = null;
        }

        if ($step === ProgressStepEnum::SET_CITY && ! $character->skin instanceof SkinEnum) {
            $character->skin = SkinEnum::Mobile;
        }
    }

    private function applyOverseerGates(Character $character): void
    {
        $character->progress_step = ProgressStepEnum::ARRIVED;
        $character->quest_phase = QuestPhaseEnum::Overseer;
    }

    private function applyOverseerTavern(Character $character): void
    {
        $character->progress_step = ProgressStepEnum::ARRIVED;
        $character->quest_phase = QuestPhaseEnum::Overseer;
        $character->overseer_at_tavern = true;
    }

    private function applyOverseerTrain(Character $character, OverseerTrainStepEnum $trainStep): void
    {
        $character->progress_step = ProgressStepEnum::ARRIVED;
        $character->quest_phase = QuestPhaseEnum::Overseer;
        $character->overseer_train_step = $trainStep;
        $character->overseer_at_tavern = false;
    }

    private function applyHealerMeet(Character $character): void
    {
        $this->writeAfterOverseerComplete($character);
    }

    private function applyHealerPotionLock(Character $character): void
    {
        $this->applyHealerMeet($character);
        $character->healer_meet_open = true;
    }

    private function applyBlacksmithMeet(Character $character): void
    {
        $this->writeAfterOverseerComplete($character);
        $this->writeAfterHealerComplete($character);
    }

    private function applyBuyerMeet(Character $character): void
    {
        $this->applyBlacksmithMeet($character);
        $this->writeAfterBlacksmithComplete($character);
    }

    private function applyBuyerTurnIn(Character $character): void
    {
        $this->applyBruiserReadyForPurse($character);
    }

    private function applyBruiserArrive(Character $character): void
    {
        $this->applyBuyerMeetBaseWithoutAmulet($character);
        $character->quest_phase = QuestPhaseEnum::Bruiser;
        $character->buyer_at_tavern = true;
        $character->unlock_arena = true;
        $character->bruiser_contract_active = true;
    }

    private function applyHealerDebt(Character $character): void
    {
        $this->applyBruiserArrive($character);
        $character->healer_debt_open = true;
    }

    private function applyHealerRatLoot(Character $character): void
    {
        $this->applyHealerDebt($character);
        $character->healer_rat_loot_pending = true;
    }

    private function applyBruiserKnucklePending(Character $character): void
    {
        $this->applyBruiserArrive($character);
        $this->writeAfterBruiserCompleteKnucklePending($character);
    }

    private function applyBruiserReadyForPurse(Character $character): void
    {
        $this->applyBruiserArrive($character);
        $this->writeAfterBruiserCompleteReady($character);
    }

    private function applyChapter1Done(Character $character): void
    {
        $character->progress_step = ProgressStepEnum::DONE;
        $character->quest_phase = QuestPhaseEnum::Done;
        $character->overseer_trained = true;
        $character->overseer_at_tavern = true;
        $character->healer_at_tavern = true;
        $character->blacksmith_at_tavern = true;
        $character->buyer_at_tavern = true;
        $character->unlock_portal = true;
        $character->unlock_ruins = true;
        $character->unlock_forest = true;
        $character->unlock_board = true;
        $character->unlock_arena = true;
        $character->city_unlocked_full = true;
    }

    private function applyBuyerMeetBaseWithoutAmulet(Character $character): void
    {
        $this->applyBlacksmithMeet($character);
        $this->writeAfterBlacksmithComplete($character);
    }
}
