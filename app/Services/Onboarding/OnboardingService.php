<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Actions\Onboarding\OnboardingBeginIntroAction;
use App\Actions\Onboarding\OnboardingFillHpAction;
use App\Actions\Onboarding\OnboardingResetToIntroAction;
use App\Actions\Onboarding\OnboardingSkipHallAction;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Quest\EquipQuest;
use App\Quest\ShopQuest;
use App\Quest\StatsQuest;
use App\Quest\TutorialQuest;
use App\Support\ActionResult;

final class OnboardingService
{
    public function __construct(
        public readonly TutorialQuest $tutorialQuest,
        public readonly StatsQuest $statsQuest,
        public readonly EquipQuest $equipQuest,
        public readonly ShopQuest $shopQuest,
        private readonly OnboardingBeginIntroAction $beginIntroAction,
        private readonly OnboardingSkipHallAction $skipHallAction,
        private readonly OnboardingResetToIntroAction $resetToIntroAction,
        private readonly OnboardingFillHpAction $fillHpAction,
    ) {}

    public function beginIntro(Character $character): Character
    {
        return $this->beginIntroAction->handle($character);
    }

    public function skipHall(Character $character): Character
    {
        return $this->skipHallAction->handle($character);
    }

    public function resetToIntro(Character $character): Character
    {
        return $this->resetToIntroAction->handle($character);
    }

    public function fillHp(Character $character): Character
    {
        return $this->fillHpAction->handle($character);
    }

    public function stepHint(string $step): string
    {
        $hints = [
            ProgressStepEnum::INTRO->value => 'onboarding.hint_intro',
            ProgressStepEnum::TUTORIAL_FIGHT->value => 'onboarding.hint_tutorial_fight',
            ProgressStepEnum::QUEST_STATS->value => 'onboarding.hint_quest_stats',
            ProgressStepEnum::QUEST_EQUIP->value => 'onboarding.hint_quest_equip',
            ProgressStepEnum::QUEST_SHOP->value => 'onboarding.hint_quest_shop',
        ];

        if (! array_key_exists($step, $hints)) {
            return __('common.continue_learning');
        }

        return __($hints[$step]);
    }

    public function introText(): string
    {
        return $this->tutorialQuest->introText();
    }

    public function startTutorialFight(Character $character): Fight
    {
        return $this->tutorialQuest->start($character);
    }

    public function onTutorialWin(Character $character): Character
    {
        return $this->tutorialQuest->onWin($character);
    }

    public function onTutorialLose(Character $character): Character
    {
        return $this->tutorialQuest->onLose($character);
    }

    public function statsQuestText(Character $character): string
    {
        return $this->statsQuest->text($character);
    }

    public function finishStatsQuest(Character $character): ActionResult
    {
        return $this->statsQuest->finish($character);
    }

    public function finishEquipQuest(Character $character): ActionResult
    {
        return $this->equipQuest->finish($character);
    }

    public function finishShopQuestBuy(Character $character, string $itemId): ActionResult
    {
        return $this->shopQuest->finishWithPurchase($character, $itemId);
    }

    public function finishShopQuestClaim(Character $character, string $itemId): ActionResult
    {
        return $this->shopQuest->finishWithTrainerClub($character, $itemId);
    }

    public function buyNovicePotion(Character $character): ActionResult
    {
        return $this->shopQuest->buyPotion($character);
    }
}
