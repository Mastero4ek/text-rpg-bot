<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\City\CityHospitalHealAction;
use App\Actions\City\CityPortalAction;
use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\LoadoutService;
use App\Services\CharacterService;
use App\Services\CityMenuService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\OnboardingService;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;

final class CityHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly CityHospitalHealAction $hospital,
        private readonly CityPortalAction $portal,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightStatusFormatter $fightStatus,
        private readonly GameConfig $config,
        private readonly LoadoutService $loadout,
        private readonly MenuHandler $menu,
        private readonly OnboardingService $onboarding,
        private readonly ShopHandler $shop,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($data === 'city:home' || $data === 'menu:home') {
            $this->home($responder, $player);

            return;
        }

        if ($data === 'city:shop') {
            $this->openShop($responder, $player);

            return;
        }

        if ($data === 'city:smith') {
            $this->openSmith($responder, $player);

            return;
        }

        if ($data === 'city:hospital') {
            $this->heal($responder, $player);

            return;
        }

        if ($data === 'city:portal') {
            $this->portalScreen($responder, $player);

            return;
        }

        if (preg_match('/^portal:(\d+)$/', $data, $m) === 1) {
            $this->travel($responder, $player, (int) $m[1]);

            return;
        }

        if ($data === 'city:arena') {
            $this->arena($responder, $player);

            return;
        }

        if ($data === 'city:forest') {
            $this->forest($responder, $player);

            return;
        }

        if ($data === 'city:training') {
            $this->training($update, $responder, $player);

            return;
        }
    }

    public function home(TelegramResponder $responder, Character $player): void
    {
        $responder->edit($this->cityMenu->homeText($player), $this->cityMenu->homeMarkup($player));
    }

    public function sendHome(TelegramResponder $responder, Character $player, string $text): void
    {
        $responder->reply($text, TelegramKeyboards::personalReply());
        $responder->reply($this->cityMenu->homeText($player), $this->cityMenu->homeMarkup($player));
    }

    private function arena(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_arena) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        $responder->edit(__('city.arena_stub'), TelegramKeyboards::backToCity());
    }

    private function forest(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_forest) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        $buttons = [];

        foreach ($this->cityQuery->forestCatalogs($city->id) as $catalog) {
            $buttons[] = [
                'text' => $this->enemies->menuLabel($catalog, $player),
                'catalog_id' => $catalog->catalog_id,
            ];
        }

        if ($buttons === []) {
            $responder->edit(__('city.forest_empty'), TelegramKeyboards::backToCity());

            return;
        }

        $responder->edit(__('combat.pick_enemy'), TelegramKeyboards::fightPick($buttons));
    }

    private function goldCost(): int
    {
        $settings = $this->config->settings();

        if (! array_key_exists('hospital', $settings) || ! is_array($settings['hospital'])) {
            throw new RuntimeException('settings.hospital missing.');
        }

        if (! array_key_exists('goldCost', $settings['hospital']) || ! is_int($settings['hospital']['goldCost'])) {
            throw new RuntimeException('settings.hospital.goldCost missing.');
        }

        return $settings['hospital']['goldCost'];
    }

    private function heal(TelegramResponder $responder, Character $player): void
    {
        $res = $this->hospital->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('city.hospital_done', ['gold' => $this->goldCost()]),
            TelegramKeyboards::backToCity(),
        );
    }

    private function openShop(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_shop) {
            $responder->reply(__('errors.no_shop'), null);

            return;
        }

        $this->shop->show($responder, $player);
    }

    private function openSmith(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_smith) {
            $responder->reply(__('errors.no_smith'), null);

            return;
        }

        $this->menu->showSmith($responder, $player);
    }

    private function portalScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_portal) {
            $responder->reply(__('errors.no_portal'), null);

            return;
        }

        $responder->edit(__('city.portal_title'), $this->cityMenu->portalMarkup($player));
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
        $player = $this->loadout->dropUnmetEquipped($player);

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);

            return null;
        }

        return $player;
    }

    private function training(TelegramUpdate $update, TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_training) {
            $responder->reply(__('errors.no_training'), null);

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        $catalog = $this->enemies->tutorialCatalog();
        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createTraining($player, $enemy);
        $messageId = $responder->reply(
            $this->fightStatus->format($fight, $player->username) . __('combat.pick_stance'),
            TelegramKeyboards::stance(),
        );
        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $messageId);
    }

    private function travel(TelegramResponder $responder, Character $player, int $targetCityId): void
    {
        $res = $this->portal->handle($player, $targetCityId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->home($responder, $res->character);
    }
}
