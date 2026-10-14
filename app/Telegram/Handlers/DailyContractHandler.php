<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Actions\DailyContract\DailyContractStartHuntAction;
use App\Actions\DailyContract\DailyContractTurnInAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\Npc\NpcEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\Character\CharacterDailyContract;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Services\CityMenuService;
use App\Services\DailyContract\DailyContractService;
use App\Services\Fight\FightPanelService;
use App\Services\Fight\FightService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\Demo\DemoMode;
use App\Support\Telegram\DailyContractCopy;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class DailyContractHandler
{
    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly DailyContractCopy $dailyCopy,
        private readonly DailyContractService $dailyContracts,
        private readonly DailyContractAcceptAction $accept,
        private readonly DailyContractStartHuntAction $startHunt,
        private readonly DailyContractTurnInAction $turnIn,
        private readonly FightPanelService $fightPanel,
        private readonly FightService $fights,
        private readonly QuestNpcPlacement $npcPlacement,
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

        if (preg_match('/^daily:offer:(\d+)$/', $data, $m) === 1) {
            $this->showOffer($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^daily:accept:(\d+)$/', $data, $m) === 1) {
            $this->acceptOffer($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^daily:hunt:(\d+)$/', $data, $m) === 1) {
            $this->hunt($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^daily:(buyer|healer|blacksmith|overseer|barkeeper|bruiser):list$/', $data, $m) === 1) {
            $issuer = NpcEnum::from($m[1]);
            $this->issuerTurnInList($responder, $player, $issuer);

            return;
        }

        if (preg_match('/^daily:(buyer|healer|blacksmith|overseer|barkeeper|bruiser):turnin:(\d+)$/', $data, $m) === 1) {
            $issuer = NpcEnum::from($m[1]);
            $this->issuerTurnIn($responder, $player, $issuer, (int) $m[2]);
        }
    }

    private function showOffer(TelegramResponder $responder, Character $player, int $contractId): void
    {
        if (! $this->boardUnlocked($player)) {
            $this->editBoardPanel(
                $responder,
                __('telegram.daily.error.board_locked'),
                CityKeyboard::backToCity(),
                null,
            );

            return;
        }

        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_quest_board) {
            $this->editBoardPanel(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToCity(),
                null,
            );

            return;
        }

        $this->dailyContracts->ensureOffers($player, $city);
        $contract = $this->dailyContracts->findOwned($player, $contractId);

        if (! $contract instanceof CharacterDailyContract || $contract->city_id !== $city->id) {
            $this->editBoardPanel(
                $responder,
                __('telegram.daily.error.not_found'),
                CityKeyboard::boardBack(),
                null,
            );

            return;
        }

        if ($contract->status !== DailyContractStatusEnum::Offered) {
            $this->editBoardPanel(
                $responder,
                __('telegram.daily.error.not_offered'),
                CityKeyboard::boardBack(),
                null,
            );

            return;
        }

        $this->editBoardPanel(
            $responder,
            $this->dailyCopy->cardText($contract),
            CityKeyboard::dailyOfferCard($contract->id),
            $this->dailyCopy->offerCardImagePath($contract),
        );
    }

    private function acceptOffer(TelegramResponder $responder, Character $player, int $contractId): void
    {
        if (! $this->boardUnlocked($player)) {
            $this->editBoardPanel(
                $responder,
                __('telegram.daily.error.board_locked'),
                CityKeyboard::backToCity(),
                null,
            );

            return;
        }

        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_quest_board) {
            $this->editBoardPanel(
                $responder,
                __('telegram.location.error.city_unavailable'),
                CityKeyboard::backToCity(),
                null,
            );

            return;
        }

        $res = $this->accept->handle($player, $city, $contractId);

        if (! $res->ok) {
            $error = $res->error;

            if (! is_string($error) || $error === '') {
                $error = __('telegram.daily.error.not_found');
            }

            $this->editBoardPanel($responder, $error, CityKeyboard::boardBack(), null);

            return;
        }

        $contract = $this->dailyContracts->findOwned($res->character ?? $player, $contractId);

        $this->editBoardPanel(
            $responder,
            $this->dailyCopy->acceptDoneText($contract),
            CityKeyboard::boardBack(),
            null,
        );
    }

    private function hunt(TelegramResponder $responder, Character $player, int $contractId): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City) {
            $responder->edit(__('telegram.location.error.city_unavailable'), CityKeyboard::backToGates());

            return;
        }

        $res = $this->startHunt->handle($player, $city, $contractId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $error = $res->error;

            if (! is_string($error) || $error === '') {
                $error = __('telegram.daily.error.not_found');
            }

            $responder->edit($error, CityKeyboard::backToGates());

            return;
        }

        $fight = $this->fights->findByTgId($res->character->tg_id);
        $contract = $this->dailyContracts->findOwned($res->character, $contractId);
        $image = null;

        if ($contract instanceof CharacterDailyContract) {
            $catalog = EnemyCatalog::query()->find($contract->enemy_catalog_id);

            if ($catalog instanceof EnemyCatalog) {
                $image = $catalog->localImagePath();
            }
        }

        $this->fightPanel->open($responder, $fight, $res->character, $image);
    }

    private function issuerTurnInList(TelegramResponder $responder, Character $player, NpcEnum $issuer): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, $issuer);

        if (! $city instanceof City || ! DailyContractService::cityHasIssuer($city, $issuer)) {
            $this->editIssuerPanel(
                $responder,
                $issuer,
                __('telegram.npc.' . $issuer->value . '.error.no_' . $issuer->value),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        $ready = $this->dailyContracts->turnInableForIssuer($player, $city, $issuer);

        if ($ready->isEmpty()) {
            $this->editIssuerPanel(
                $responder,
                $issuer,
                __('telegram.daily.error.not_ready'),
                CityKeyboard::npcOfferWithDaily($issuer, false, $player->stat_points, $back),
            );

            return;
        }

        $contract = $ready->firstOrFail();
        $reward = DemoMode::rewardAmounts($player, $contract->reward_exp, $contract->reward_silver);
        $turnInText = __('telegram.daily.turnin_list', [
            'target' => $this->dailyCopy->turnInTarget($contract),
            'silver' => $reward['silver'],
            'exp' => $reward['exp'],
        ]);

        $this->editIssuerPanel(
            $responder,
            $issuer,
            DemoMode::withRewardFrozenNotice($player, $turnInText),
            CityKeyboard::dailyNpcTurnIn(
                $issuer,
                $contract->id,
                $this->npcPlacement->npcLaterCallback($player, $issuer),
            ),
        );
    }

    private function issuerTurnIn(
        TelegramResponder $responder,
        Character $player,
        NpcEnum $issuer,
        int $contractId,
    ): void {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, $issuer);

        if (! $city instanceof City || ! DailyContractService::cityHasIssuer($city, $issuer)) {
            $this->editIssuerPanel(
                $responder,
                $issuer,
                __('telegram.npc.' . $issuer->value . '.error.no_' . $issuer->value),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        $contract = $this->dailyContracts->findOwned($player, $contractId);
        $silver = 0;
        $exp = 0;
        $rewardFrozen = DemoMode::isProgressionCapped($player);

        if ($contract instanceof CharacterDailyContract) {
            $silver = $contract->reward_silver;
            $exp = $contract->reward_exp;
        }

        $res = $this->turnIn->handle($player, $city, $contractId, $issuer);

        if (! $res->ok) {
            $error = $res->error;

            if (! is_string($error) || $error === '') {
                $error = __('telegram.daily.error.not_found');
            }

            $this->editIssuerPanel(
                $responder,
                $issuer,
                $error,
                CityKeyboard::backToNpc($issuer),
            );

            return;
        }

        $panelChar = $res->character ?? $player;

        if ($rewardFrozen) {
            $silver = 0;
            $exp = 0;
        }

        $doneText = __('telegram.daily.turnin_done', [
            'silver' => $silver,
            'exp' => $exp,
        ]);

        if ($rewardFrozen) {
            $doneText .= "\n\n" . __('profile.demo_reward_frozen');
        }

        $hubDeliveryDone = $issuer === NpcEnum::Overseer
            && ! $this->npcPlacement->hasAcceptedDeliveryHere($panelChar, NpcEnum::Overseer);

        if ($hubDeliveryDone) {
            $this->editIssuerPanel(
                $responder,
                $issuer,
                $doneText,
                CityKeyboard::toCityHub(),
            );

            return;
        }

        $backAfter = $this->npcPlacement->npcBackCallback($panelChar, $issuer);
        $ready = $this->dailyContracts->turnInableForIssuer($panelChar, $city, $issuer);

        $this->editIssuerPanel(
            $responder,
            $issuer,
            $doneText,
            CityKeyboard::npcOfferWithDaily(
                $issuer,
                $ready->isNotEmpty(),
                $panelChar->stat_points,
                $backAfter,
            ),
        );
    }

    private function boardUnlocked(Character $player): bool
    {
        if (! $player->unlock_board) {
            return false;
        }

        return $player->quest_phase === QuestPhaseEnum::Done;
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBoardPanel(
        TelegramResponder $responder,
        string $text,
        array $markup,
        ?string $imagePath,
    ): void {
        $image = $imagePath;

        if ($image === null) {
            $boardImage = config('bot.city_quest_board_image');

            if (is_string($boardImage) && $boardImage !== '' && is_file($boardImage)) {
                $image = $boardImage;
            }
        }

        if (is_string($image) && $image !== '' && is_file($image)) {
            try {
                $responder->editPhoto($image, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editIssuerPanel(
        TelegramResponder $responder,
        NpcEnum $issuer,
        string $text,
        array $markup,
    ): void {
        $imageKey = match ($issuer) {
            NpcEnum::Buyer => 'bot.buyer_tavern_image',
            NpcEnum::Healer => 'bot.healer_tavern_image',
            NpcEnum::Blacksmith => 'bot.blacksmith_tavern_image',
            NpcEnum::Overseer => 'bot.overseer_image',
            NpcEnum::Barkeeper => 'bot.barkeeper_tavern_image',
            NpcEnum::Bruiser => 'bot.bruiser_port_image',
        };

        $image = config($imageKey);

        if (is_string($image) && $image !== '' && is_file($image)) {
            try {
                $responder->editPhoto($image, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }
}
