<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\City\CityHealerHealAction;
use App\Actions\City\CityPortalAction;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Queries\City\CityQuery;
use App\Services\CityMenuService;
use App\Services\EnemyService;
use App\Services\Fight\FightService;
use App\Services\Fight\FightStatusFormatter;
use App\Services\GameConfig;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class CityHandler
{
    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly CityHealerHealAction $healer,
        private readonly CityPortalAction $portal,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightStatusFormatter $fightStatus,
        private readonly GameConfig $config,
        private readonly SmithHandler $smith,
        private readonly RegistrationService $registrationService,
        private readonly ShopHandler $shop,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($player->progress_step === ProgressStepEnum::ARRIVED) {
            $this->home($responder, $player);

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

        if (preg_match('/^city:training:start:(.+)$/', $data, $m) === 1) {
            $this->trainingStart($update, $responder, $player, $m[1]);

            return;
        }

        if ($data === 'city:training') {
            $this->trainingPick($responder, $player);
        }
    }

    public function home(TelegramResponder $responder, Character $player): void
    {
        $text = $this->cityMenu->homeText($player);
        $markup = $this->cityMenu->homeMarkup($player);
        $imagePath = $this->homePanelImagePath($player);

        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    public function resumeHomePanel(TelegramResponder $responder, Character $player): int
    {
        $text = $this->cityMenu->homeText($player);
        $markup = $this->cityMenu->homeMarkup($player);
        $imagePath = $this->homePanelImagePath($player);

        if ($player->tg_chat_id !== null && $player->tg_message_id !== null) {
            if ($imagePath !== null) {
                try {
                    $responder->editPhotoAt(
                        $player->tg_chat_id,
                        $player->tg_message_id,
                        $imagePath,
                        $text,
                        $markup,
                    );

                    return $player->tg_message_id;
                } catch (Throwable) {
                }
            }

            try {
                $responder->editCaptionAt(
                    $player->tg_chat_id,
                    $player->tg_message_id,
                    $text,
                    $markup,
                );

                return $player->tg_message_id;
            } catch (Throwable) {
            }
        }

        return $this->sendHomePanel($responder, $player);
    }

    public function sendHome(TelegramResponder $responder, Character $player, string $text): void
    {
        $responder->reply($text, TelegramKeyboards::removeReply());
        $this->sendHomePanel($responder, $player);
    }

    public function sendHomePanel(TelegramResponder $responder, Character $player): int
    {
        $text = $this->cityMenu->homeText($player);
        $markup = $this->cityMenu->homeMarkup($player);
        $imagePath = $this->homePanelImagePath($player);

        if ($imagePath !== null) {
            $messageId = $responder->replyPhoto($imagePath, $text, $markup);
            $this->registrationService->rememberTelegramMessage(
                $player,
                $responder->chatId(),
                $messageId,
            );

            return $messageId;
        }

        $messageId = $responder->reply($text, $markup);
        $this->registrationService->rememberTelegramMessage($player, $responder->chatId(), $messageId);

        return $messageId;
    }

    public function showTavern(TelegramResponder $responder, Character $player): void
    {
        $this->tavern($responder, $player);
    }

    private function homePanelImagePath(Character $player): ?string
    {
        if ($player->progress_step === ProgressStepEnum::ARRIVED) {
            $attendant = config('bot.training_attendant_image');

            if (is_string($attendant) && $attendant !== '' && is_file($attendant)) {
                return $attendant;
            }
        }

        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City) {
            return null;
        }

        $media = $city->getFirstMedia('image');

        if (! $media instanceof Media) {
            return null;
        }

        $imagePath = $media->getPath();

        if (! is_file($imagePath)) {
            return null;
        }

        return $imagePath;
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
            $text = __('telegram.location.arena_empty');
        } else {
            $text = __('telegram.location.arena');
        }

        $arenaImage = config('bot.city_arena_image');

        if (is_string($arenaImage) && $arenaImage !== '' && is_file($arenaImage)) {
            try {
                $responder->editPhoto($arenaImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function blacksmith(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $responder->reply(__('errors.no_blacksmith'), null);

            return;
        }

        $responder->edit(__('telegram.npc.blacksmith'), CityKeyboard::blacksmith());
    }

    private function board(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_quest_board) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        $text = __('telegram.location.board_empty');
        $markup = CityKeyboard::backToCity();
        $boardImage = config('bot.city_quest_board_image');

        if (is_string($boardImage) && $boardImage !== '' && is_file($boardImage)) {
            try {
                $responder->editPhoto($boardImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function fightsList(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_fights_list) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        $responder->edit(__('telegram.location.fights_empty'), CityKeyboard::arena($city));
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
            $text = __('telegram.location.forest_empty');
            $markup = CityKeyboard::backToGates();
        } else {
            $text = __('telegram.location.forest');
            $markup = TelegramKeyboards::fightPick($buttons);
        }

        $forestImage = config('bot.city_forest_image');

        if (is_string($forestImage) && $forestImage !== '' && is_file($forestImage)) {
            try {
                $responder->editPhoto($forestImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
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
            $text = __('telegram.location.gates_empty');
        } else {
            $text = __('telegram.location.gates');
        }

        $gatesImage = config('bot.city_gates_image');

        if (is_string($gatesImage) && $gatesImage !== '' && is_file($gatesImage)) {
            try {
                $responder->editPhoto($gatesImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
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
            $responder->edit(
                TelegramResponder::errorMessage($res->error),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $responder->edit(
            __('telegram.npc.healer_done', ['gold' => $this->goldCost()]),
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

        $this->smith->showRepair($responder, $player);
    }

    private function overseer(TelegramResponder $responder, Character $player): void
    {
        if (! $player->onboarding_skipped) {
            $this->tavern($responder, $player);

            return;
        }

        $responder->edit(__('telegram.npc.overseer_skip'), CityKeyboard::overseerOffer());
    }

    private function portalScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_portal) {
            $responder->reply(__('errors.no_portal'), null);

            return;
        }

        $text = __('telegram.location.portal_title');
        $markup = $this->cityMenu->portalMarkup($player);
        $portalImage = config('bot.city_portal_image');

        if (is_string($portalImage) && $portalImage !== '' && is_file($portalImage)) {
            try {
                $responder->editPhoto($portalImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function tavern(TelegramResponder $responder, Character $player): void
    {
        $markup = $this->cityMenu->tavernMarkup($player);

        if ($markup === null) {
            $responder->reply(__('errors.city_unavailable'), null);

            return;
        }

        if ($this->cityMenu->tavernHasNpc($player)) {
            $text = __('telegram.location.tavern');
        } else {
            $text = __('telegram.location.tavern_empty');
        }

        $tavernImage = config('bot.city_tavern_image');

        if (is_string($tavernImage) && $tavernImage !== '' && is_file($tavernImage)) {
            try {
                $responder->editPhoto($tavernImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function trainingPick(TelegramResponder $responder, Character $player): void
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

        $buttons = [];

        foreach ($this->cityQuery->trainingCatalogs($city->id) as $catalog) {
            $buttons[] = [
                'text' => $this->enemies->menuLabel($catalog, $player),
                'catalog_id' => $catalog->catalog_id,
            ];
        }

        if ($buttons === []) {
            $responder->edit(__('telegram.location.training_empty'), CityKeyboard::backToArena());

            return;
        }

        $responder->edit(__('telegram.location.training'), CityKeyboard::trainingPick($buttons));
    }

    private function trainingStart(
        TelegramUpdate $update,
        TelegramResponder $responder,
        Character $player,
        string $catalogId,
    ): void {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_training_room) {
            $responder->reply(__('errors.no_training'), null);

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        $catalog = null;

        foreach ($this->cityQuery->trainingCatalogs($city->id) as $row) {
            if ($row->catalog_id === $catalogId) {
                $catalog = $row;
            }
        }

        if (! $catalog instanceof EnemyCatalog) {
            $responder->reply(__('errors.enemy_not_found'), null);

            return;
        }

        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createHall($player, $enemy);
        $text = $this->fightStatus->format($fight, $player->username) . __('combat.pick_stance');
        $markup = TelegramKeyboards::stance();
        $imagePath = $catalog->localImagePath();

        if ($imagePath !== null) {
            $messageId = $responder->replyPhoto($imagePath, $text, $markup);
        } else {
            $messageId = $responder->reply($text, $markup);
        }

        $this->fights->rememberTelegramMessage($fight, $update->chatId(), $messageId);
    }

    private function travel(TelegramResponder $responder, Character $player, int $targetCityId): void
    {
        $res = $this->portal->handle($player, $targetCityId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->deleteUpdateMessage();
        $this->sendHomePanel($responder, $res->character);
    }
}
