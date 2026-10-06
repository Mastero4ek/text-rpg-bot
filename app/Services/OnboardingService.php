<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Fight;
use App\Quest\EquipQuest;
use App\Quest\ShopQuest;
use App\Quest\StatsQuest;
use App\Quest\TutorialQuest;
use App\Support\ActionResult;
use App\Support\NickValidator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OnboardingService
{
    public function __construct(
        private readonly GameConfig $config,
        private readonly CharacterService $characters,
        private readonly NickValidator $nickValidator,
        public readonly TutorialQuest $tutorialQuest,
        public readonly StatsQuest $statsQuest,
        public readonly EquipQuest $equipQuest,
        public readonly ShopQuest $shopQuest,
    ) {}

    /**
     * @return list<string>
     */
    public function cities(): array
    {
        $keys = $this->cityKeys();
        $names = [];

        foreach ($keys as $key) {
            $names[] = __('onboarding.cities.' . $key);
        }

        return $names;
    }

    public function stepHint(string $step): string
    {
        $hints = [
            OnboardingStepEnum::NICK->value => 'onboarding.hint_nick',
            OnboardingStepEnum::CITY->value => 'onboarding.hint_city',
            OnboardingStepEnum::INTRO->value => 'onboarding.hint_intro',
            OnboardingStepEnum::TUTORIAL_FIGHT->value => 'onboarding.hint_tutorial_fight',
            OnboardingStepEnum::QUEST_STATS->value => 'onboarding.hint_quest_stats',
            OnboardingStepEnum::QUEST_EQUIP->value => 'onboarding.hint_quest_equip',
            OnboardingStepEnum::QUEST_SHOP->value => 'onboarding.hint_quest_shop',
        ];

        if (! array_key_exists($step, $hints)) {
            return __('common.continue_learning');
        }

        return __($hints[$step]);
    }

    public function ensurePlayer(int $tgId): Character
    {
        $character = Character::withTrashed()->find($tgId);

        if ($character === null) {
            return $this->characters->createDraft($tgId);
        }

        return $character;
    }

    public function setNick(Character $character, string $raw): ActionResult
    {
        return DB::transaction(function () use ($character, $raw): ActionResult {
            $error = $this->nickValidator->validate($raw);

            if ($error !== null) {
                return ActionResult::fail($error);
            }

            $nick = mb_trim($raw);

            if ($this->characters->usernameTakenByOther($nick, $character->tg_id)) {
                return ActionResult::fail(__('errors.nick_taken'));
            }

            $character->username = $nick;
            $character->onboarding_step = OnboardingStepEnum::CITY;
            $character->save();

            return ActionResult::ok($character);
        });
    }

    public function setLocation(Character $character, string $location): ActionResult
    {
        return DB::transaction(function () use ($character, $location): ActionResult {
            if (! in_array($location, $this->cities(), true)) {
                return ActionResult::fail(__('errors.pick_city_button'));
            }

            $character->location = $location;
            $character->onboarding_step = OnboardingStepEnum::INTRO;
            $character->save();

            return ActionResult::ok($character);
        });
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

    /**
     * @return list<string>
     */
    private function cityKeys(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('cityKeys', $onboarding) || ! is_array($onboarding['cityKeys'])) {
            throw new RuntimeException('onboarding.cityKeys missing.');
        }

        $keys = [];

        foreach ($onboarding['cityKeys'] as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid city key.');
            }

            $keys[] = $key;
        }

        return $keys;
    }
}
