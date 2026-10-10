<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\City\CityPortalAction;
use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Enums\Enemy\EnemyKindEnum;
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
use App\Telegram\Keyboards\PaginatedListKeyboard;
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
        $boardState = PaginatedListKeyboard::listState($data, 'city:board');
        $forestState = PaginatedListKeyboard::listState($data, 'city:forest');
        $fightsState = PaginatedListKeyboard::listState($data, 'city:fights');
        $trainingState = PaginatedListKeyboard::listState($data, 'city:training');
        $portalState = PaginatedListKeyboard::listState($data, 'portal');

        if (
            $boardState !== null
            || $forestState !== null
            || $fightsState !== null
            || $trainingState !== null
            || $portalState !== null
            || $data === 'city:board'
            || $data === 'city:forest'
            || $data === 'city:fights'
            || $data === 'city:training'
            || $data === 'city:portal'
        ) {
            $player = $this->gate->requireCityPlayer($update, $responder);

            if ($player === false) {
                $responder->answerCallback();

                return;
            }

            if ($player->progress_step === ProgressStepEnum::ARRIVED) {
                $responder->answerCallback();
                $this->home($responder, $player);

                return;
            }

            if ($boardState !== null) {
                $this->board($responder, $player, $boardState['filter'], $boardState['page']);

                return;
            }

            if ($data === 'city:board') {
                $this->board($responder, $player, 'all', 1);

                return;
            }

            if ($forestState !== null) {
                $this->forest($responder, $player, $forestState['filter'], $forestState['page']);

                return;
            }

            if ($data === 'city:forest') {
                $this->forest($responder, $player, 'all', 1);

                return;
            }

            if ($fightsState !== null) {
                $this->fightsList($responder, $player, $fightsState['page']);

                return;
            }

            if ($data === 'city:fights') {
                $this->fightsList($responder, $player, 1);

                return;
            }

            if ($trainingState !== null) {
                $this->trainingPick($responder, $player, $trainingState['filter'], $trainingState['page']);

                return;
            }

            if ($data === 'city:training') {
                $this->trainingPick($responder, $player, 'all', 1);

                return;
            }

            if ($portalState !== null) {
                $this->portalScreen($responder, $player, $portalState['filter'], $portalState['page']);

                return;
            }

            $this->portalScreen($responder, $player, 'all', 1);

            return;
        }

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

        if ($data === 'city:gates') {
            $this->gates($responder, $player);

            return;
        }

        if ($data === 'city:overseer') {
            $this->overseer($responder, $player);

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
        $this->forest($responder, $player, 'all', 1);
    }

    public function openTrainingPick(TelegramResponder $responder, Character $player): void
    {
        $this->trainingPick($responder, $player, 'all', 1);
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
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToCity(),
            );

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

    private function board(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_quest_board) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToCity(),
            );

            return;
        }

        $filter = $this->boardFilter($filter);
        $markup = CityKeyboard::boardList([], $filter, $page);

        $responder->answerCallback();
        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        $text = __('telegram.location.board_empty');
        $boardImage = config('bot.city_quest_board_image');

        if (is_string($boardImage) && $boardImage !== '' && is_file($boardImage)) {
            try {
                $responder->editPhoto($boardImage, $text, $markup);
                $this->registrationService->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
        $this->registrationService->rememberTelegramMessage(
            $player,
            $responder->chatId(),
            $responder->messageId(),
        );
    }

    private function boardFilter(string $filter): string
    {
        if (in_array($filter, ['all', 'orders', 'asks'], true)) {
            return $filter;
        }

        return 'all';
    }

    private function fightsList(TelegramResponder $responder, Character $player, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_fights_list) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        $responder->answerCallback();
        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $responder->edit(
            __('telegram.location.fights_empty'),
            CityKeyboard::fightsList([], $page),
        );
        $this->registrationService->rememberTelegramMessage(
            $player,
            $responder->chatId(),
            $responder->messageId(),
        );
    }

    private function forest(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $panel = $this->forestPanel($player, $filter, $page);

        if ($panel === null) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_forest'),
                CityKeyboard::backToGates(),
            );

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_hp'),
                CityKeyboard::backToGates(),
            );

            return;
        }

        $responder->answerCallback();
        $this->showListPanel($responder, $player, $panel, __('telegram.location.forest_empty'), __('telegram.location.forest'));
    }

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}, image: string|null, flash: bool}|null
     */
    private function forestPanel(Character $player, string $filter, int $page): ?array
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_forest) {
            return null;
        }

        $filter = $this->enemyFilter($filter);
        $buttons = $this->enemyButtons($this->cityQuery->forestCatalogs($city->id), $player, $filter);
        $edge = PaginatedListKeyboard::isOutOfRange($page, count($buttons));
        $flash = $edge && ! empty($buttons);

        if ($edge || empty($buttons)) {
            $text = __('telegram.location.forest_empty');
        } else {
            $text = __('telegram.location.forest');
        }

        $forestImage = config('bot.city_forest_image');

        if (! is_string($forestImage) || $forestImage === '' || ! is_file($forestImage)) {
            $forestImage = null;
        }

        return [
            'text' => $text,
            'markup' => TelegramKeyboards::fightPick($buttons, $filter, $page),
            'image' => $forestImage,
            'flash' => $flash,
        ];
    }

    private function sendForest(TelegramResponder $responder, Character $player): void
    {
        $panel = $this->forestPanel($player, 'all', 1);

        if ($panel === null) {
            $this->sendCityPanel(
                $responder,
                $player,
                __('telegram.location.error.no_forest'),
                CityKeyboard::backToGates(),
                null,
            );

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
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToCity(),
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if (! $city->has_portal && ! $city->has_forest) {
            $text = __('telegram.location.gates_empty');
        } else {
            $text = __('telegram.location.gates');
        }

        $gatesImage = config('bot.city_gates_image');

        if (is_string($gatesImage) && $gatesImage !== '' && is_file($gatesImage)) {
            try {
                $responder->editPhoto($gatesImage, $text, $markup);
                $this->registrationService->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
        $this->registrationService->rememberTelegramMessage(
            $player,
            $responder->chatId(),
            $responder->messageId(),
        );
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

    private function portalScreen(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_portal) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_portal'),
                CityKeyboard::backToGates(),
            );

            return;
        }

        $filter = $this->portalFilter($filter);
        $targets = $this->portalTargetList($player, $filter);
        $edge = PaginatedListKeyboard::isOutOfRange($page, count($targets));
        $markup = CityKeyboard::portalTargets($targets, $filter, $page);

        $responder->answerCallback();

        if ($edge && ! empty($targets)) {
            $this->flashListPageEdge(
                $responder,
                __('telegram.location.portal_empty'),
                __('telegram.location.portal_title'),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if (empty($targets)) {
            $text = __('telegram.location.portal_empty');
        } else {
            $text = __('telegram.location.portal_title');
        }

        $portalImage = config('bot.city_portal_image');

        if (is_string($portalImage) && $portalImage !== '' && is_file($portalImage)) {
            try {
                $responder->editPhoto($portalImage, $text, $markup);
                $this->registrationService->rememberTelegramMessage(
                    $player,
                    $responder->chatId(),
                    $responder->messageId(),
                );

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
        $this->registrationService->rememberTelegramMessage(
            $player,
            $responder->chatId(),
            $responder->messageId(),
        );
    }

    private function tavern(TelegramResponder $responder, Character $player): void
    {
        $markup = $this->cityMenu->tavernMarkup($player);

        if ($markup === null) {
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToCity(),
            );

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

    private function trainingPick(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $panel = $this->trainingPanel($player, $filter, $page);

        if ($panel === null) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_training'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        if ($player->current_hp <= 0) {
            $responder->answerCallback();
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_hp'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        $responder->answerCallback();
        $this->showListPanel($responder, $player, $panel, __('telegram.location.training_empty'), __('telegram.location.training'));
    }

    /**
     * @return array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}, image: null, flash: bool}|null
     */
    private function trainingPanel(Character $player, string $filter, int $page): ?array
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_training_room) {
            return null;
        }

        $filter = $this->enemyFilter($filter);
        $buttons = $this->enemyButtons($this->cityQuery->trainingCatalogs($city->id), $player, $filter);
        $edge = PaginatedListKeyboard::isOutOfRange($page, count($buttons));
        $flash = $edge && ! empty($buttons);

        if ($edge || empty($buttons)) {
            $text = __('telegram.location.training_empty');
        } else {
            $text = __('telegram.location.training');
        }

        return [
            'text' => $text,
            'markup' => CityKeyboard::trainingPick($buttons, $filter, $page),
            'image' => null,
            'flash' => $flash,
        ];
    }

    private function sendTrainingPick(TelegramResponder $responder, Character $player): void
    {
        $panel = $this->trainingPanel($player, 'all', 1);

        if ($panel === null) {
            $this->sendCityPanel(
                $responder,
                $player,
                __('telegram.location.error.no_training'),
                CityKeyboard::backToArena(),
                null,
            );

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
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_training'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        if ($player->current_hp <= 0) {
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.no_hp'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        $catalog = null;

        foreach ($this->cityQuery->trainingCatalogs($city->id) as $row) {
            if ($row->catalog_id === $catalogId) {
                $catalog = $row;
            }
        }

        if (! $catalog instanceof EnemyCatalog) {
            $this->editLocationGate(
                $responder,
                __('telegram.location.error.enemy_not_found'),
                CityKeyboard::backToArena(),
            );

            return;
        }

        $enemy = $this->enemies->makeFromCatalog($catalog, $player);
        $fight = $this->fights->createHall($player, $enemy);
        $this->fightPanel->open($responder, $fight, $player, $catalog->localImagePath());
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function flashListPageEdge(
        TelegramResponder $responder,
        string $flashText,
        string $restoreText,
        array $markup,
    ): void {
        app(FlashListPageEdgeAction::class)->handle(
            $responder,
            $flashText,
            $restoreText,
            $markup,
        );
    }

    /**
     * @param  array{text: string, markup: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}, image: string|null, flash: bool}  $panel
     */
    private function showListPanel(
        TelegramResponder $responder,
        Character $player,
        array $panel,
        string $emptyText,
        string $normalText,
    ): void {
        if ($panel['flash']) {
            $this->flashListPageEdge(
                $responder,
                $emptyText,
                $normalText,
                $panel['markup'],
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $this->replaceCityPanel(
            $responder,
            $player,
            $panel['text'],
            $panel['markup'],
            $panel['image'],
        );
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
        $chatId = $responder->chatId();
        $messageId = $responder->messageId();

        if ($imagePath !== null) {
            try {
                $responder->editPhotoAt($chatId, $messageId, $imagePath, $text, $markup);
                $this->registrationService->rememberTelegramMessage($player, $chatId, $messageId);

                return;
            } catch (Throwable) {
            }
        }

        try {
            if ($responder->callbackHasPhoto()) {
                $responder->editCaptionAt($chatId, $messageId, $text, $markup);
            } else {
                $responder->editAt($chatId, $messageId, $text, $markup);
            }

            $this->registrationService->rememberTelegramMessage($player, $chatId, $messageId);

            return;
        } catch (Throwable) {
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
            $this->editLocationGate(
                $responder,
                $this->portalTravelErrorText($res->error),
                CityKeyboard::backToGates(),
            );

            return;
        }

        $responder->deleteUpdateMessage();
        $this->sendHomePanel($responder, $res->character);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editLocationGate(TelegramResponder $responder, string $text, array $markup): void
    {
        $responder->edit($text, $markup);
    }

    private function portalTravelErrorText(?string $error): string
    {
        if ($error === __('errors.same_city')) {
            return __('telegram.location.error.same_city');
        }

        if ($error === __('errors.not_enough_silver')) {
            return __('telegram.location.error.not_enough_silver');
        }

        if ($error === __('errors.city_unavailable')) {
            return __('telegram.location.error.city_unavailable');
        }

        return TelegramResponder::errorMessage($error);
    }

    /**
     * @param  iterable<int, EnemyCatalog>  $catalogs
     * @return list<array{text: string, catalog_id: string}>
     */
    private function enemyButtons(iterable $catalogs, Character $player, string $filter): array
    {
        $kind = CityKeyboard::enemyKindFromFilter($filter);
        $buttons = [];

        foreach ($catalogs as $catalog) {
            if ($kind instanceof EnemyKindEnum && $catalog->kind !== $kind) {
                continue;
            }

            $buttons[] = [
                'text' => $this->enemies->menuLabel($catalog, $player),
                'catalog_id' => $catalog->catalog_id,
            ];
        }

        return $buttons;
    }

    private function enemyFilter(string $filter): string
    {
        if ($filter === 'all' || CityKeyboard::enemyKindFromFilter($filter) instanceof EnemyKindEnum) {
            return $filter;
        }

        return 'all';
    }

    private function portalFilter(string $filter): string
    {
        if (in_array($filter, ['all', 'free', 'paid'], true)) {
            return $filter;
        }

        return 'all';
    }

    /**
     * @return list<City>
     */
    private function portalTargetList(Character $player, string $filter): array
    {
        if ($player->city_id === null) {
            return [];
        }

        $targets = [];

        foreach ($this->cityQuery->portalTargets($player->city_id) as $target) {
            if ($filter === 'free' && $target->portal_cost_silver > 0) {
                continue;
            }

            if ($filter === 'paid' && $target->portal_cost_silver <= 0) {
                continue;
            }

            $targets[] = $target;
        }

        return $targets;
    }
}
