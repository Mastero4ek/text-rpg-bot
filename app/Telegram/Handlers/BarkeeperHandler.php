<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\LuckyBarrel\LuckyBarrelCashOutAction;
use App\Actions\LuckyBarrel\LuckyBarrelForfeitAction;
use App\Actions\LuckyBarrel\LuckyBarrelOpenAction;
use App\Actions\LuckyBarrel\LuckyBarrelStartAction;
use App\Enums\LuckyBarrel\LuckyBarrelOutcomeEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Models\LuckyBarrel;
use App\Services\CityMenuService;
use App\Services\DailyContract\DailyContractService;
use App\Services\LuckyBarrel\LuckyBarrelService;
use App\Services\Quest\QuestNpcPlacement;
use App\Support\LuckyBarrel\LuckyBarrelResult;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class BarkeeperHandler
{
    public function __construct(
        private readonly CityMenuService $cityMenu,
        private readonly DailyContractService $dailyContracts,
        private readonly LuckyBarrelCashOutAction $cashOut,
        private readonly LuckyBarrelForfeitAction $forfeit,
        private readonly LuckyBarrelOpenAction $open,
        private readonly LuckyBarrelService $luckyBarrels,
        private readonly LuckyBarrelStartAction $start,
        private readonly QuestNpcPlacement $npcPlacement,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            $responder->answerCallback();

            return;
        }

        if ($data === 'city:barkeeper') {
            $responder->answerCallback();
            $this->barkeeperScreen($responder, $player);

            return;
        }

        if ($data === 'city:barkeeper:game') {
            $responder->answerCallback();
            $this->gameEntry($responder, $player);

            return;
        }

        if ($data === 'city:barkeeper:game:back') {
            $responder->answerCallback();
            $this->forfeitToOffer($responder, $player);

            return;
        }

        if ($data === 'city:barkeeper:game:cash') {
            $this->cashOutScreen($responder, $player);

            return;
        }

        if (preg_match('/^city:barkeeper:game:stake:(\d+)$/', $data, $m) === 1) {
            $this->stakeStart($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^city:barkeeper:game:open:(\d+)$/', $data, $m) === 1) {
            $this->openBarrel($responder, $player, (int) $m[1]);

            return;
        }

        $responder->answerCallback();
    }

    private function barkeeperScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Barkeeper);

        if (! $city instanceof City || ! $city->has_barkeeper) {
            $this->editBarkeeperPanel(
                $responder,
                __('telegram.npc.barkeeper.error.no_barkeeper'),
                CityKeyboard::backToCallback($back),
                config('bot.barkeeper_tavern_image'),
            );

            return;
        }

        $session = $this->luckyBarrels->findInProgress($player->tg_id);

        if ($session instanceof LuckyBarrel) {
            $this->showPlay($responder, $session);

            return;
        }

        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($player, $city, NpcEnum::Barkeeper)
            ->isNotEmpty();

        $this->editBarkeeperPanel(
            $responder,
            __('telegram.npc.barkeeper.offer'),
            CityKeyboard::barkeeperOfferWithDaily($hasDaily, $back),
            config('bot.barkeeper_tavern_image'),
        );
    }

    private function gameEntry(TelegramResponder $responder, Character $player): void
    {
        if (! $this->requireBarkeeperCity($responder, $player)) {
            return;
        }

        $session = $this->luckyBarrels->findInProgress($player->tg_id);

        if ($session instanceof LuckyBarrel) {
            $this->showPlay($responder, $session);

            return;
        }

        $this->showStake($responder, $player);
    }

    private function forfeitToOffer(TelegramResponder $responder, Character $player): void
    {
        if (! $this->requireBarkeeperCity($responder, $player)) {
            return;
        }

        $this->forfeit->handle($player);
        $fresh = Character::query()->find($player->tg_id);

        if ($fresh instanceof Character) {
            $player = $fresh;
        }

        $this->barkeeperScreen($responder, $player);
    }

    private function stakeStart(TelegramResponder $responder, Character $player, int $stake): void
    {
        if (! $this->requireBarkeeperCity($responder, $player)) {
            $responder->answerCallback();

            return;
        }

        $result = $this->start->handle($player, $stake);
        $responder->answerCallback();

        if (! $result->ok) {
            $error = $result->error;

            if ($error === null) {
                $error = __('telegram.npc.barkeeper.error.no_session');
            }

            $this->showStake($responder, $player, $error);

            return;
        }

        $this->showOutcome($responder, $player, $result);
    }

    private function openBarrel(TelegramResponder $responder, Character $player, int $barrelIndex): void
    {
        if (! $this->requireBarkeeperCity($responder, $player)) {
            $responder->answerCallback();

            return;
        }

        $result = $this->open->handle($player, $barrelIndex);

        if ($result->ok && $result->outcome === LuckyBarrelOutcomeEnum::Noop) {
            $responder->answerCallback();

            return;
        }

        $responder->answerCallback();

        if (! $result->ok) {
            $this->gameEntry($responder, $player);

            return;
        }

        $this->showOutcome($responder, $player, $result);
    }

    private function cashOutScreen(TelegramResponder $responder, Character $player): void
    {
        if (! $this->requireBarkeeperCity($responder, $player)) {
            $responder->answerCallback();

            return;
        }

        $result = $this->cashOut->handle($player);

        if ($result->ok && $result->outcome === LuckyBarrelOutcomeEnum::Noop) {
            $responder->answerCallback();

            return;
        }

        $responder->answerCallback();

        if (! $result->ok) {
            $this->gameEntry($responder, $player);

            return;
        }

        $this->showOutcome($responder, $player, $result);
    }

    private function showOutcome(TelegramResponder $responder, Character $player, LuckyBarrelResult $result): void
    {
        if ($result->character instanceof Character) {
            $player = $result->character;
        }

        if ($result->outcome === LuckyBarrelOutcomeEnum::Play && $result->session instanceof LuckyBarrel) {
            $this->showPlay($responder, $result->session);

            return;
        }

        if ($result->outcome === LuckyBarrelOutcomeEnum::Win
            || $result->outcome === LuckyBarrelOutcomeEnum::WinMax) {
            $win = $result->payout;

            if ($win === null) {
                $win = 0;
            }

            $winKey = 'telegram.npc.barkeeper.game.win';

            if ($result->outcome === LuckyBarrelOutcomeEnum::WinMax) {
                $winKey = 'telegram.npc.barkeeper.game.win_max';
            }

            $this->editBarkeeperPanel(
                $responder,
                __($winKey, [
                    'win' => TelegramHtml::escape((string) $win),
                ]),
                CityKeyboard::luckyBarrelEnd(),
                config('bot.lucky_barrels_image'),
            );

            return;
        }

        if ($result->outcome === LuckyBarrelOutcomeEnum::Lose) {
            $stake = $result->stake;
            $ratIndex = $result->ratIndex;

            if ($stake === null) {
                $stake = 0;
            }

            if ($ratIndex === null) {
                $ratIndex = 0;
            }

            $this->editBarkeeperPanel(
                $responder,
                __('telegram.npc.barkeeper.game.lose', [
                    'stake' => TelegramHtml::escape((string) $stake),
                    'rat_index' => TelegramHtml::escape((string) $ratIndex),
                ]),
                CityKeyboard::luckyBarrelEnd(),
                config('bot.lucky_barrels_image'),
            );
        }
    }

    private function showStake(TelegramResponder $responder, Character $player, ?string $errorPrefix = null): void
    {
        $text = __('telegram.npc.barkeeper.game.stake', [
            'silver' => TelegramHtml::escape((string) $player->silver),
        ]);

        if ($errorPrefix !== null) {
            $text = $errorPrefix . "\n\n" . $text;
        }

        $this->editBarkeeperPanel(
            $responder,
            $text,
            CityKeyboard::luckyBarrelStake($this->luckyBarrels->stakes()),
            config('bot.lucky_barrels_image'),
        );
    }

    private function showPlay(TelegramResponder $responder, LuckyBarrel $session): void
    {
        $this->editBarkeeperPanel(
            $responder,
            __('telegram.npc.barkeeper.game.play', [
                'stake' => TelegramHtml::escape((string) $session->base_stake),
                'win' => TelegramHtml::escape(
                    $this->luckyBarrels->currentWinLabel($session->base_stake, $session->current_step),
                ),
                'next_mult' => TelegramHtml::escape(
                    $this->luckyBarrels->nextMultiplierLabel($session->current_step),
                ),
            ]),
            CityKeyboard::luckyBarrelPlay($session->opened_barrels, $session->current_step),
            config('bot.lucky_barrels_image'),
        );
    }

    private function requireBarkeeperCity(TelegramResponder $responder, Character $player): bool
    {
        $city = $this->cityMenu->currentCity($player);
        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Barkeeper);

        if ($city instanceof City && $city->has_barkeeper) {
            return true;
        }

        $this->editBarkeeperPanel(
            $responder,
            __('telegram.npc.barkeeper.error.no_barkeeper'),
            CityKeyboard::backToCallback($back),
            config('bot.barkeeper_tavern_image'),
        );

        return false;
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBarkeeperPanel(TelegramResponder $responder, string $text, array $markup, mixed $image): void
    {
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
