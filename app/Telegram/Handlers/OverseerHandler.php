<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Character\CharacterResetStatsForGoldAction;
use App\Actions\Character\CharacterSpendStatPointAction;
use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Services\CharacterService;
use App\Services\CityMenuService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class OverseerHandler
{
    public function __construct(
        private readonly CharacterResetStatsForGoldAction $resetStatsForGold,
        private readonly CharacterService $characters,
        private readonly CharacterSpendStatPointAction $spendStatPoint,
        private readonly CityHandler $city,
        private readonly CityMenuService $cityMenu,
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

        if ($data === 'city:overseer') {
            $this->overseerScreen($responder, $player);

            return;
        }

        if (preg_match('/^city:overseer:stat:(STRENGTH|AGILITY|INSTINCT|VITALITY)$/', $data, $m) === 1) {
            $this->spendStat($responder, $player, $m[1]);

            return;
        }

        if ($data === 'city:overseer:reset') {
            $this->resetConfirm($responder, $player);

            return;
        }

        if ($data === 'city:overseer:reset_yes') {
            $this->resetStats($responder, $player);
        }
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editOverseerPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $overseerImage = config('bot.overseer_tavern_image');

        if (is_string($overseerImage) && $overseerImage !== '' && is_file($overseerImage)) {
            try {
                $responder->editPhoto($overseerImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function overseerScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($player->onboarding_skipped) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.skip'),
                CityKeyboard::overseerOffer(),
            );

            return;
        }

        if ($player->progress_step !== ProgressStepEnum::DONE) {
            $this->city->showTavern($responder, $player);

            return;
        }

        $this->editOverseerPanel(
            $responder,
            $this->statsPanelText($player),
            CityKeyboard::overseerStats($player->stat_points),
        );
    }

    private function resetConfirm(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($player->onboarding_skipped || $player->progress_step !== ProgressStepEnum::DONE) {
            $this->overseerScreen($responder, $player);

            return;
        }

        $this->editOverseerPanel(
            $responder,
            __('telegram.npc.overseer.reset_confirm', [
                'gold' => $this->characters->statResetGoldCost(),
            ]),
            CityKeyboard::overseerResetConfirm(),
        );
    }

    private function resetDoneText(Character $character): string
    {
        return __('telegram.npc.overseer.reset_done', [
            'points' => $character->stat_points,
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
        ]);
    }

    private function resetStats(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($player->onboarding_skipped || $player->progress_step !== ProgressStepEnum::DONE) {
            $this->overseerScreen($responder, $player);

            return;
        }

        $res = $this->resetStatsForGold->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            if ($res->error === __('errors.not_enough_gold')) {
                $this->editOverseerPanel(
                    $responder,
                    __('telegram.npc.overseer.error.not_enough_gold'),
                    CityKeyboard::backToOverseer(),
                );

                return;
            }

            $this->editOverseerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                CityKeyboard::backToOverseer(),
            );

            return;
        }

        $this->editOverseerPanel(
            $responder,
            $this->resetDoneText($res->character),
            CityKeyboard::overseerStats($res->character->stat_points),
        );
    }

    private function spendStat(TelegramResponder $responder, Character $player, string $stat): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_overseer) {
            $this->editOverseerPanel(
                $responder,
                __('telegram.npc.overseer.error.no_overseer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($player->onboarding_skipped || $player->progress_step !== ProgressStepEnum::DONE) {
            $this->overseerScreen($responder, $player);

            return;
        }

        $res = $this->spendStatPoint->handle($player, $stat);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editOverseerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                CityKeyboard::overseerStats($player->stat_points),
            );

            return;
        }

        $this->editOverseerPanel(
            $responder,
            $this->statsPanelText($res->character),
            CityKeyboard::overseerStats($res->character->stat_points),
        );
    }

    private function statsPanelText(Character $character): string
    {
        if ($character->stat_points > 0) {
            return __('telegram.npc.overseer.offer', [
                'points' => $character->stat_points,
                'str' => $character->strength,
                'agi' => $character->agility,
                'inst' => $character->instinct,
                'vit' => $character->vitality,
            ]);
        }

        return __('telegram.npc.overseer.error.no_points', [
            'str' => $character->strength,
            'agi' => $character->agility,
            'inst' => $character->instinct,
            'vit' => $character->vitality,
        ]);
    }
}
