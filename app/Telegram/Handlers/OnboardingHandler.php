<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Character\CharacterSetLocationAction;
use App\Actions\Character\CharacterSetNickAction;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\OnboardingService;
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
        private readonly CharacterSetNickAction $setNick,
        private readonly CharacterSetLocationAction $setLocation,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly ShopCatalog $shop,
        private readonly GameConfig $config,
        private readonly FightStatusFormatter $fightStatus,
        private readonly FightService $fights,
        private readonly CityHandler $city,
    ) {}

    public function handleStart(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->onboarding->ensurePlayer($update->userId());

        if ($player->onboarding_step === OnboardingStepEnum::DONE) {
            $player = $this->characters->applyRegen($player);
            $this->city->sendHome(
                $responder,
                $player,
                __('onboarding.welcome_back', [
                    'name' => $player->username,
                    'profile' => $this->characters->profileText($player),
                ]),
            );

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

        if ($player->onboarding_step === OnboardingStepEnum::NICK) {
            $res = $this->setNick->handle($player, $update->text());

            if (! $res->ok || ! $res->character instanceof Character) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            $responder->reply(
                __('onboarding.nice_to_meet', ['name' => $res->character->username]),
                TelegramKeyboards::city($this->onboarding->cities()),
            );

            return;
        }

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);
            $this->resume($responder, $player);
        }
    }

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();

        if (str_starts_with($data, 'ob:city:')) {
            $this->city($update, $responder, mb_substr($data, 8));

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

    private function resume(TelegramResponder $responder, Character $player): void
    {
        $nick = $this->nickLimits();

        if ($player->onboarding_step === OnboardingStepEnum::NICK) {
            $responder->reply(
                __('onboarding.welcome', [
                    'nickMin' => $nick['min'],
                    'nickMax' => $nick['max'],
                ]),
                TelegramKeyboards::removeReply(),
            );

            return;
        }

        if ($player->onboarding_step === OnboardingStepEnum::CITY) {
            $responder->reply(__('onboarding.pick_city'), TelegramKeyboards::city($this->onboarding->cities()));

            return;
        }

        if ($player->onboarding_step === OnboardingStepEnum::INTRO) {
            $responder->reply($this->onboarding->introText(), TelegramKeyboards::intro());

            return;
        }

        if ($player->onboarding_step === OnboardingStepEnum::TUTORIAL_FIGHT) {
            $this->resumeTutorialFight($responder, $player);

            return;
        }

        if ($player->onboarding_step === OnboardingStepEnum::QUEST_STATS) {
            $responder->reply(
                $this->onboarding->statsQuestText($player),
                TelegramKeyboards::statsQuest($player),
            );

            return;
        }

        if ($player->onboarding_step === OnboardingStepEnum::QUEST_EQUIP) {
            $responder->reply(__('onboarding.equip_prompt'), TelegramKeyboards::equipMail());

            return;
        }

        if ($player->onboarding_step === OnboardingStepEnum::QUEST_SHOP) {
            $responder->reply(
                __('onboarding.shop_prompt', ['silver' => $player->silver]),
                TelegramKeyboards::noviceShop($this->shop, $this->bagCatalog->potionPrice(), $this->requireCity($player)),
            );

            return;
        }

        $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);
    }

    private function resumeTutorialFight(TelegramResponder $responder, Character $player): void
    {
        if (! $this->fights->exists($player->tg_id)) {
            $player->onboarding_step = OnboardingStepEnum::INTRO;
            $player->save();
            $responder->reply($this->onboarding->introText(), TelegramKeyboards::intro());

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

    private function city(TelegramUpdate $update, TelegramResponder $responder, string $cityName): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::CITY) {
            return;
        }

        $res = $this->setLocation->handle($player, $cityName);

        if (! $res->ok) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $chosen = $res->character;
        $chosen->loadMissing('city');

        if ($chosen->city instanceof City) {
            $cityLabel = $chosen->city->name;
        } else {
            $cityLabel = $cityName;
        }

        $responder->edit(
            __('onboarding.city_chosen', [
                'city' => $cityLabel,
                'intro' => $this->onboarding->introText(),
            ]),
            TelegramKeyboards::intro(),
        );
    }

    private function introFight(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null) {
            return;
        }

        if (
            $player->onboarding_step !== OnboardingStepEnum::INTRO
            && $player->onboarding_step !== OnboardingStepEnum::TUTORIAL_FIGHT
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

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::QUEST_STATS) {
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

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::QUEST_STATS) {
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

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::QUEST_EQUIP) {
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

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::QUEST_SHOP) {
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
                'level' => $this->graduateLevel(),
                'profile' => $this->characters->profileText($res->character),
            ]),
        );
    }

    private function claimClub(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::QUEST_SHOP) {
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
                'level' => $this->graduateLevel(),
                'profile' => $this->characters->profileText($res->character),
            ]),
        );
    }

    private function novicePotion(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = Character::query()->find($update->userId());

        if ($player === null || $player->onboarding_step !== OnboardingStepEnum::QUEST_SHOP) {
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

    /**
     * @return array{min: int, max: int}
     */
    private function nickLimits(): array
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('nick', $onboarding) || ! is_array($onboarding['nick'])) {
            throw new RuntimeException('onboarding.nick missing.');
        }

        $nick = $onboarding['nick'];

        if (! is_int($nick['min']) || ! is_int($nick['max'])) {
            throw new RuntimeException('onboarding.nick invalid.');
        }

        return ['min' => $nick['min'], 'max' => $nick['max']];
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

    private function graduateLevel(): int
    {
        $onboarding = $this->config->onboarding();

        if (! array_key_exists('graduateLevel', $onboarding) || ! is_int($onboarding['graduateLevel'])) {
            throw new RuntimeException('onboarding.graduateLevel missing.');
        }

        return $onboarding['graduateLevel'];
    }
}
