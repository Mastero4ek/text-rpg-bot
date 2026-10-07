<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\City\CityHealerHealAction;
use App\Actions\City\CityPortalAction;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\LoadoutService;
use App\Services\CharacterService;
use App\Services\CityMenuService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\GameConfig;
use App\Services\Onboarding\OnboardingService;
use App\Services\Registration\RegistrationFlow;
use App\Support\Telegram\FightStatusFormatter;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;

final class CityHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly CityHealerHealAction $healer,
        private readonly CityPortalAction $portal,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightStatusFormatter $fightStatus,
        private readonly GameConfig $config,
        private readonly LoadoutService $loadout,
        private readonly MenuHandler $menu,
        private readonly OnboardingService $onboarding,
        private readonly RegistrationFlow $registration,
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

        if ($data === 'city:arena') {
            $this->arena($responder, $player);

            return;
        }

        if ($data === 'city:board') {
            $this->board($responder, $player);

            return;
        }

        if ($data === 'city:buyer') {
            $this->openBuyer($responder, $player);

            return;
        }

        if ($data === 'city:blacksmith') {
            $this->blacksmith($responder, $player);

            return;
        }

        if ($data === 'city:blacksmith:gear') {
            $this->openGear($responder, $player);

            return;
        }

        if ($data === 'city:blacksmith:repair') {
            $this->openRepair($responder, $player);

            return;
        }

        if ($data === 'city:fights') {
            $this->fightsList($responder, $player);

            return;
        }

        if ($data === 'city:forest') {
            $this->forest($responder, $player);

            return;
        }

        if ($data === 'city:gates') {
            $this->gates($responder, $player);

            return;
        }

        if ($data === 'city:healer') {
            $this->heal($responder, $player);

            return;
        }

        if ($data === 'city:overseer') {
            $this->overseer($responder, $player);

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

        if ($data === 'city:tavern') {
            $this->tavern($responder, $player);

            return;
        }

        if ($data === 'city:training') {
            $this->training($update, $responder, $player);
        }
    }

    public function home(TelegramResponder $responder, Character $player): void
    {
        $responder->edit($this->cityMenu->homeText($player), $this->cityMenu->homeMarkup($player));
    }

    public function sendHome(TelegramResponder $responder, Character $player, string $text): void
    {
        $responder->reply($text, TelegramKeyboards::removeReply());
        $responder->reply($this->cityMenu->homeText($player), $this->cityMenu->homeMarkup($player));
    }

    public function sendHomePanel(TelegramResponder $responder, Character $player): void
    {
        $responder->reply(
            $this->cityMenu->homeText($player),
            $this->cityMenu->homeMarkup($player),
        );
    }

    public function showTavern(TelegramResponder $responder, Character $player): void
    {
        $this->tavern($responder, $player);
    }

    private function arena(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $markup = $this->cityMenu->arenaMarkup($player);

        if (! $city instanceof City || $markup === null) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        if (! $city->has_training_room && ! $city->has_fights_list) {
            $responder->edit(__('telegram.city.arena_empty'), $markup);

            return;
        }

        $responder->edit(__('telegram.city.arena'), $markup);
    }

    private function blacksmith(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $responder->reply(__('errors.no_blacksmith'), null);

            return;
        }

        $responder->edit(__('telegram.city.blacksmith'), CityKeyboard::blacksmith());
    }

    private function board(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_quest_board) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        $responder->edit(__('telegram.city.board_empty'), CityKeyboard::backToCity());
    }

    private function fightsList(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_fights_list) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        $responder->edit(__('telegram.city.fights_empty'), CityKeyboard::arena($city));
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
            $responder->edit(__('telegram.city.forest_empty'), CityKeyboard::backToCity());

            return;
        }

        $responder->edit(__('combat.pick_enemy'), TelegramKeyboards::fightPick($buttons));
    }

    private function gates(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $markup = $this->cityMenu->gatesMarkup($player);

        if (! $city instanceof City || $markup === null) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        if (! $city->has_portal && ! $city->has_forest) {
            $responder->edit(__('telegram.city.gates_empty'), $markup);

            return;
        }

        $responder->edit(__('telegram.city.gates'), $markup);
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
        $res = $this->healer->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('telegram.city.healer_done', ['gold' => $this->goldCost()]),
            CityKeyboard::backToTavern(),
        );
    }

    private function openBuyer(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $responder->reply(__('errors.no_buyer'), null);

            return;
        }

        $this->shop->showBuyer($responder, $player);
    }

    private function openGear(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $responder->reply(__('errors.no_blacksmith'), null);

            return;
        }

        $this->shop->showGear($responder, $player);
    }

    private function openRepair(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $responder->reply(__('errors.no_blacksmith'), null);

            return;
        }

        $this->menu->showRepair($responder, $player);
    }

    private function overseer(TelegramResponder $responder, Character $player): void
    {
        if (! $player->onboarding_skipped) {
            $this->tavern($responder, $player);

            return;
        }

        $responder->edit(__('telegram.city.overseer_skip'), CityKeyboard::overseerOffer());
    }

    private function portalScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_portal) {
            $responder->reply(__('errors.no_portal'), null);

            return;
        }

        $responder->edit(__('telegram.city.portal_title'), $this->cityMenu->portalMarkup($player));
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

        if ($this->registration->isActive($player)) {
            $this->registration->showNudge($responder, $player);

            return null;
        }

        if (! $player->progress_step->canPlayCity()) {
            $responder->reply($this->onboarding->stepHint($player->progress_step->value), null);

            return null;
        }

        return $player;
    }

    private function tavern(TelegramResponder $responder, Character $player): void
    {
        $markup = $this->cityMenu->tavernMarkup($player);

        if ($markup === null) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        if (! $this->cityMenu->tavernHasNpc($player)) {
            $responder->edit(__('telegram.city.tavern_empty'), $markup);

            return;
        }

        $responder->edit(__('telegram.city.tavern'), $markup);
    }

    private function training(TelegramUpdate $update, TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_training_room) {
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
