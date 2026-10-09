<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Character\CharacterResetStatsForGoldAction;
use App\Actions\Character\CharacterSpendStatPointAction;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\Fight\FightService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;

final class MenuHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly FightService $fights,
        private readonly CharacterSpendStatPointAction $spendStatPoint,
        private readonly CharacterResetStatsForGoldAction $resetStatsForGold,
        private readonly InventoryHandler $inventory,
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
            $responder->edit($this->characters->profileText($player), CityKeyboard::backToCity());

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
            ! in_array($command, ['character', 'skills', 'backpack', 'bag'], true)
        ) {
            return;
        }

        $responder->deleteUpdateMessage();

        if ($command === 'character') {
            $responder->reply($this->characters->profileText($player), CityKeyboard::backToCity());

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
            [$text, $markup] = $this->inventory->backpackPanel($player, null);
            $responder->reply($text, $markup);

            return;
        }

        [$text, $markup] = $this->inventory->bagPanel($player);
        $responder->reply($text, $markup);
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
            $responder->reply($this->characters->profileText($player), CityKeyboard::backToCity());

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

    private function statsResetConfirm(TelegramResponder $responder): void
    {
        $responder->edit(
            __('profile.stats_reset_confirm', [
                'gold' => $this->characters->statResetGoldCost(),
            ]),
            TelegramKeyboards::statsResetConfirm(),
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
}
