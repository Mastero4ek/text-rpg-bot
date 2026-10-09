<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\City\CityPortalAction;
use App\Enums\Fight\FightReturnEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Queries\City\CityQuery;
use App\Services\CityMenuService;
use App\Services\EnemyService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightService;
use App\Services\Registration\RegistrationService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class CityHandler
{
    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly CityPortalAction $portal,
        private readonly EnemyService $enemies,
        private readonly FightService $fights,
        private readonly FightPanelService $fightPanel,
        private readonly RegistrationService $registrationService,
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
            $this->trainingStart($responder, $player, $m[1]);

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

    public function openForest(TelegramResponder $responder, Character $player): void
    {
        $this->forest($responder, $player);
    }

    public function openTrainingPick(TelegramResponder $responder, Character $player): void
    {
        $this->trainingPick($responder, $player);
    }

    public function returnAfterFight(TelegramResponder $responder, Character $player): void
    {
        $returnTo = $player->fight_return;

        if (! $returnTo instanceof FightReturnEnum) {
            return;
        }

        $player->fight_return = null;
        $player->save();

        if ($returnTo === FightReturnEnum::Training) {
            $this->sendTrainingPick($responder, $player);

            return;
        }

        $this->sendForest($responder, $player);
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
        $panel = $this->forestPanel($player);

        if ($panel === null) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        $this->replaceCityPanel(
            $responder,
            $player,
            $panel['text'],
            $panel['markup'],
            $panel['image'],
        );
    }

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}, image: string|null}|null
     */
    private function forestPanel(Character $player): ?array
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_forest) {
            return null;
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

        if (! is_string($forestImage) || $forestImage === '' || ! is_file($forestImage)) {
            $forestImage = null;
        }

        return [
            'text' => $text,
            'markup' => $markup,
            'image' => $forestImage,
        ];
    }

    private function sendForest(TelegramResponder $responder, Character $player): void
    {
        $panel = $this->forestPanel($player);

        if ($panel === null) {
            $responder->reply(__('errors.no_forest'), null);

            return;
        }

        $this->sendCityPanel(
            $responder,
            $player,
            $panel['text'],
            $panel['markup'],
            $panel['image'],
        );
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

    private function overseer(TelegramResponder $responder, Character $player): void
    {
        if (! $player->onboarding_skipped) {
            $this->tavern($responder, $player);

            return;
        }

        $text = __('telegram.npc.overseer.skip');
        $markup = CityKeyboard::overseerOffer();
        $attendantImage = config('bot.training_attendant_tavern_image');

        if (is_string($attendantImage) && $attendantImage !== '' && is_file($attendantImage)) {
            try {
                $responder->editPhoto($attendantImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
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
        $panel = $this->trainingPanel($player);

        if ($panel === null) {
            $responder->reply(__('errors.no_training'), null);

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->reply(__('errors.no_hp'), null);

            return;
        }

        $this->replaceCityPanel(
            $responder,
            $player,
            $panel['text'],
            $panel['markup'],
            $panel['image'],
        );
    }

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}, image: null}|null
     */
    private function trainingPanel(Character $player): ?array
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_training_room) {
            return null;
        }

        $buttons = [];

        foreach ($this->cityQuery->trainingCatalogs($city->id) as $catalog) {
            $buttons[] = [
                'text' => $this->enemies->menuLabel($catalog, $player),
                'catalog_id' => $catalog->catalog_id,
            ];
        }

        if ($buttons === []) {
            return [
                'text' => __('telegram.location.training_empty'),
                'markup' => CityKeyboard::backToArena(),
                'image' => null,
            ];
        }

        return [
            'text' => __('telegram.location.training'),
            'markup' => CityKeyboard::trainingPick($buttons),
            'image' => null,
        ];
    }

    private function sendTrainingPick(TelegramResponder $responder, Character $player): void
    {
        $panel = $this->trainingPanel($player);

        if ($panel === null) {
            $responder->reply(__('errors.no_training'), null);

            return;
        }

        $arenaImage = config('bot.city_arena_image');

        if (! is_string($arenaImage) || $arenaImage === '' || ! is_file($arenaImage)) {
            $arenaImage = null;
        }

        $this->sendCityPanel(
            $responder,
            $player,
            $panel['text'],
            $panel['markup'],
            $arenaImage,
        );
    }

    private function trainingStart(
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
        $this->fightPanel->open($responder, $fight, $player, $catalog->localImagePath());
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function replaceCityPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        ?array $markup,
        ?string $imagePath,
    ): void {
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

                    return;
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

                return;
            } catch (Throwable) {
            }
        }

        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        } else {
            try {
                $responder->edit($text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $this->sendCityPanel($responder, $player, $text, $markup, $imagePath);
    }

    /**
     * @param  array<string, mixed>|null  $markup
     */
    private function sendCityPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        ?array $markup,
        ?string $imagePath,
    ): void {
        if ($imagePath !== null) {
            $messageId = $responder->replyPhoto($imagePath, $text, $markup);
            $this->registrationService->rememberTelegramMessage(
                $player,
                $responder->chatId(),
                $messageId,
            );

            return;
        }

        $messageId = $responder->reply($text, $markup);
        $this->registrationService->rememberTelegramMessage($player, $responder->chatId(), $messageId);
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
