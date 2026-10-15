<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Character\CharacterResetStatsForGoldAction;
use App\Actions\Character\CharacterSpendStatPointAction;
use App\Models\Character\Character;
use App\Services\Character\CharacterService;
use App\Services\Fight\FightService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

final class MenuHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CityHandler $city,
        private readonly FightService $fights,
        private readonly CharacterSpendStatPointAction $spendStatPoint,
        private readonly CharacterResetStatsForGoldAction $resetStatsForGold,
        private readonly InventoryHandler $inventory,
        private readonly JournalHandler $journal,
        private readonly LibraryHandler $library,
        private readonly AppealHandler $appeal,
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

        if ($data === 'menu:profile') {
            $this->showProfile($responder, $player);

            return;
        }

        if ($data === 'menu:stats') {
            $this->statsScreen($responder, $player);

            return;
        }

        if (preg_match('/^stat:(STRENGTH|AGILITY|INSTINCT|VITALITY)$/', $data, $m) === 1) {
            $this->spendStat($responder, $player, $m[1]);

            return;
        }

        if ($data === 'stat:reset') {
            $this->statsResetConfirm($responder);

            return;
        }

        if ($data === 'stat:reset_yes') {
            $this->statsReset($responder, $player);
        }
    }

    public function handleCommand(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($this->fights->exists($player->tg_id)) {
            return;
        }

        $command = $update->botCommand();

        if ($command === null) {
            return;
        }

        if (
            ! in_array($command, ['city', 'profile', 'backpack', 'bag', 'journal', 'library', 'appeal', 'character', 'skills'], true)
        ) {
            return;
        }

        $responder->deleteUpdateMessage();

        if ($command === 'city') {
            $this->city->sendHomePanel($responder, $player);

            return;
        }

        if ($command === 'profile' || $command === 'character') {
            $this->replyProfile($responder, $player);

            return;
        }

        if ($command === 'skills') {
            $responder->reply(
                $this->characters->statsScreenText($player),
                TelegramKeyboards::statsScreen(
                    $player->stat_points,
                    $this->characters->statResetGoldCost(),
                ),
            );

            return;
        }

        if ($command === 'backpack') {
            $this->inventory->sendBackpack($responder, $player);

            return;
        }

        if ($command === 'journal') {
            $this->journal->sendList($responder, $player);

            return;
        }

        if ($command === 'library') {
            $this->library->sendHome($responder, $player);

            return;
        }

        if ($command === 'appeal') {
            $this->appeal->sendList($responder, $player);

            return;
        }

        $this->inventory->sendBag($responder, $player);
    }

    public function handleText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($this->fights->exists($player->tg_id)) {
            return;
        }

        $text = $update->text();

        if ($text === __('menu.profile')) {
            $this->replyProfile($responder, $player);

            return;
        }

        if ($text === __('menu.inv')) {
            $responder->reply(__('menu.inventory'), TelegramKeyboards::inventoryHub());

            return;
        }

        if ($text === __('menu.stats')) {
            $responder->reply(
                $this->characters->statsScreenText($player),
                TelegramKeyboards::statsScreen(
                    $player->stat_points,
                    $this->characters->statResetGoldCost(),
                ),
            );
        }
    }

    private function replyProfile(TelegramResponder $responder, Character $player): void
    {
        $text = $this->characters->profileText($player);
        $markup = TelegramKeyboards::profile();
        $skin = $this->characters->skinImagePath($player);

        if ($skin !== null) {
            $responder->replyPhoto($skin, $text, $markup);

            return;
        }

        $responder->reply($text, $markup);
    }

    private function showProfile(TelegramResponder $responder, Character $player): void
    {
        $text = $this->characters->profileText($player);
        $markup = TelegramKeyboards::profile();
        $skin = $this->characters->skinImagePath($player);

        if ($skin !== null) {
            try {
                $responder->editPhoto($skin, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function spendStat(TelegramResponder $responder, Character $player, string $stat): void
    {
        $res = $this->spendStatPoint->handle($player, $stat);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            $this->characters->statsScreenText($res->character),
            TelegramKeyboards::statsScreen(
                $res->character->stat_points,
                $this->characters->statResetGoldCost(),
            ),
        );
    }

    private function statsReset(TelegramResponder $responder, Character $player): void
    {
        $res = $this->resetStatsForGold->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('profile.stats_reset_done', [
                'screen' => $this->characters->statsScreenText($res->character),
            ]),
            TelegramKeyboards::statsScreen(
                $res->character->stat_points,
                $this->characters->statResetGoldCost(),
            ),
        );
    }

    private function statsResetConfirm(TelegramResponder $responder): void
    {
        $responder->edit(
            __('profile.stats_reset_confirm', [
                'gold' => $this->characters->statResetGoldCost(),
            ]),
            TelegramKeyboards::statsResetConfirm(),
        );
    }

    private function statsScreen(TelegramResponder $responder, Character $player): void
    {
        $responder->edit(
            $this->characters->statsScreenText($player),
            TelegramKeyboards::statsScreen(
                $player->stat_points,
                $this->characters->statResetGoldCost(),
            ),
        );
    }
}
