<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\Onboarding\OnboardingService;
use App\Services\Registration\RegistrationFlow;
use App\Services\Shop\ShopCatalog;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;

final class OnboardingHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly OnboardingService $onboarding,
        private readonly RegistrationFlow $registrationFlow,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly ShopCatalog $shop,
        private readonly GameConfig $config,
        private readonly FightStatusFormatter $fightStatus,
        private readonly FightService $fights,
        private readonly CityHandler $city,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        if ($data === 'ob:hall') {
            $this->enterHall($update, $responder);

            return;
        }

        if ($data === 'ob:pass') {
            $this->passHall($update, $responder);

            return;
        }

        if ($data === 'ob:intro_fight') {
            $this->introFight($update, $responder);

            return;
        }

        if (preg_match('/^ob:stat:(STRENGTH|AGILITY|INSTINCT|VITALITY)$/', $data, $m) === 1) {
            $this->stat($update, $responder, $m[1]);

            return;
        }

        if ($data === 'ob:stats_done') {
            $this->statsDone($update, $responder);

            return;
        }

        if ($data === 'ob:equip_mail') {
            $this->equipMail($update, $responder);

            return;
        }

        if (str_starts_with($data, 'ob:buy:')) {
            $this->buyWeapon($update, $responder, mb_substr($data, 7));

            return;
        }

        if ($data === 'ob:claim_club') {
            $this->claimClub($update, $responder);

            return;
        }

        if ($data === 'ob:novice_potion') {
            $this->novicePotion($update, $responder);
        }
    }

    public function handleStart(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            return;
        }

        if (
            $player->progress_step->canPlayCity()
            || $this->registrationFlow->isActive($player)
        ) {
            return;
        }

        $this->resume($responder, $player);
    }

    public function handleText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return;
        }

        if (
            $player->progress_step->canPlayCity()
            || $this->registrationFlow->isActive($player)
        ) {
            return;
        }

        $responder->reply($this->onboarding->stepHint($player->progress_step->value), null);
        $this->resume($responder, $player);
    }

    public function startIntro(TelegramResponder $responder): void
    {
        $responder->reply($this->onboarding->introText(), TelegramKeyboards::intro());
    }

    private function enterHall(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return;
        }

        if ($player->progress_step === ProgressStepEnum::ARRIVED) {
            $player->progress_step = ProgressStepEnum::INTRO;
            $player->save();
            $responder->edit(__('telegram.city.hall_gone'), TelegramKeyboards::clearInline());
            $this->startIntro($responder);

            return;
        }

        if ($player->progress_step === ProgressStepEnum::INTRO) {
            $this->startIntro($responder);

            return;
        }

        if ($player->progress_step->canPlayCity()) {
            return;
        }

        $responder->reply($this->onboarding->stepHint($player->progress_step->value), null);
        $this->resume($responder, $player);
    }

    private function passHall(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            $responder->reply(__('common.press_start'), null);

            return;
        }

        if ($player->progress_step !== ProgressStepEnum::ARRIVED) {
            return;
        }

        $player->progress_step = ProgressStepEnum::DONE;
        $player->save();
        $player = $this->characters->applyRegen($player);
        $this->city->home($responder, $player);
    }

    private function resume(TelegramResponder $responder, Character $player): void
    {
        if ($player->progress_step === ProgressStepEnum::INTRO) {
            $this->startIntro($responder);

            return;
        }

        if ($player->progress_step === ProgressStepEnum::TUTORIAL_FIGHT) {
            $this->resumeTutorialFight($responder, $player);

            return;
        }

        if ($player->progress_step === ProgressStepEnum::QUEST_STATS) {
            $responder->reply(
                $this->onboarding->statsQuestText($player),
                TelegramKeyboards::statsQuest($player),
            );

            return;
        }

        if ($player->progress_step === ProgressStepEnum::QUEST_EQUIP) {
            $responder->reply(__('onboarding.equip_prompt'), TelegramKeyboards::equipMail());

            return;
        }

        if ($player->progress_step === ProgressStepEnum::QUEST_SHOP) {
            $responder->reply(
                __('onboarding.shop_prompt', ['silver' => $player->silver]),
                TelegramKeyboards::noviceShop($this->shop, $this->bagCatalog->potionPrice(), $this->requireCity($player)),
            );

            return;
        }

        $responder->reply($this->onboarding->stepHint($player->progress_step->value), null);
    }

    private function resumeTutorialFight(TelegramResponder $responder, Character $player): void
    {
        if (! $this->fights->exists($player->tg_id)) {
            $player->progress_step = ProgressStepEnum::INTRO;
            $player->save();
            $this->startIntro($responder);

            return;
        }

        $fight = $this->fights->findByTgId($player->tg_id);
        $base = $this->fightStatus->format($fight, $player->username);

        if ($fight->step === FightStepEnum::STANCE) {
            $responder->reply($base . __('combat.pick_stance'), TelegramKeyboards::stance());

            return;
        }

        if ($fight->step === FightStepEnum::ATTACK_SECOND) {
            $responder->reply($base . __('combat.pick_attack_second'), TelegramKeyboards::attackWithoutPotion());

            return;
        }

        if ($fight->step === FightStepEnum::ATTACK) {
            $responder->reply($base . __('combat.pick_attack'), TelegramKeyboards::attackWithoutPotion());

            return;
        }

        if ($fight->step === FightStepEnum::DEFEND_SECOND && $fight->player_defend instanceof ZoneEnum) {
            $responder->reply(
                $base . __('combat.pick_defend_second'),
                TelegramKeyboards::defendExcluding($fight->player_defend),
            );

            return;
        }

        if ($fight->use_potion) {
            $prompt = __('combat.potion_then_defend');
        } else {
            $prompt = __('combat.pick_defend');
        }

        $responder->reply($base . $prompt, TelegramKeyboards::defend());
    }

    private function introFight(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            return;
        }

        if (
            $player->progress_step !== ProgressStepEnum::INTRO
            && $player->progress_step !== ProgressStepEnum::TUTORIAL_FIGHT
        ) {
            return;
        }

        $player = $this->characters->applyRegen($player);
        $player->current_hp = $this->characters->maxHp($player);
        $player->save();
        $fight = $this->onboarding->startTutorialFight($player);
        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $update->messageId());

        $responder->edit(
            $this->fightStatus->format($fight, $player->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
    }

    private function stat(TelegramUpdate $update, TelegramResponder $responder, string $stat): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::QUEST_STATS) {
            return;
        }

        $res = $this->characters->spendStatPoint($player, $stat);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            $this->onboarding->statsQuestText($res->character),
            TelegramKeyboards::statsQuest($res->character),
        );
    }

    private function statsDone(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::QUEST_STATS) {
            return;
        }

        $res = $this->onboarding->finishStatsQuest($player);

        if (! $res->ok) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('onboarding.stats_done', [
                'armorHp' => $this->shop->mailShirt()->statBonus,
            ]),
            TelegramKeyboards::equipMail(),
        );
    }

    private function equipMail(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::QUEST_EQUIP) {
            return;
        }

        $res = $this->onboarding->finishEquipQuest($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $reward = $this->equipReward();

        $responder->edit(
            __('onboarding.mail_equipped', [
                'exp' => $reward['exp'],
                'silverReward' => $reward['silver'],
                'silver' => $res->character->silver,
            ]),
            TelegramKeyboards::noviceShop($this->shop, $this->bagCatalog->potionPrice(), $this->requireCity($player)),
        );
    }

    private function buyWeapon(TelegramUpdate $update, TelegramResponder $responder, string $itemId): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::QUEST_SHOP) {
            return;
        }

        $res = $this->onboarding->finishShopQuestBuy($player, $itemId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->city->sendHome(
            $responder,
            $res->character,
            __('onboarding.graduated_buy', [
                'profile' => $this->characters->profileText($res->character),
            ]),
        );
    }

    private function claimClub(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::QUEST_SHOP) {
            return;
        }

        $res = $this->onboarding->finishShopQuestClaim($player, $this->shop->freeTrainerItemId());

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->city->sendHome(
            $responder,
            $res->character,
            __('onboarding.graduated_claim', [
                'profile' => $this->characters->profileText($res->character),
            ]),
        );
    }

    private function novicePotion(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->progress_step !== ProgressStepEnum::QUEST_SHOP) {
            return;
        }

        $res = $this->onboarding->buyNovicePotion($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('onboarding.potion_bought', [
                'potions' => $this->bag->potionCountByProfile(
                    $res->character->tg_id,
                    ProfileEnum::HEAL,
                ),
                'silver' => $res->character->silver,
            ]),
            TelegramKeyboards::noviceShop($this->shop, $this->bagCatalog->potionPrice(), $this->requireCity($player)),
        );
    }

    private function requireCity(Character $player): City
    {
        $player->loadMissing('city');

        if (! $player->city instanceof City) {
            throw new RuntimeException('Onboarding city missing.');
        }

        return $player->city;
    }

    /**
     * @return array{exp: int, silver: int}
     */
    private function equipReward(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('rewards', $onboarding) || ! is_array($onboarding['rewards'])) {
            throw new RuntimeException('onboarding.rewards missing.');
        }

        $row = $onboarding['rewards']['equipQuest'] ?? null;

        if (! is_array($row) || ! is_int($row['exp']) || ! is_int($row['silver'])) {
            throw new RuntimeException('equipQuest reward missing.');
        }

        return ['exp' => $row['exp'], 'silver' => $row['silver']];
    }
}
