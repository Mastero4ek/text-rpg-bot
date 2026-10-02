<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Services\Character\CharacterService;
use App\Services\Inventory\InventoryService;
use App\Services\Onboarding\OnboardingService;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class MenuHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly InventoryService $inventory,
        private readonly OnboardingService $onboarding,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $responder->answerCallback();
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($data === 'menu:profile' || $data === 'menu:home') {
            $responder->edit($this->characters->profileText($player), TelegramKeyboards::mainMenu());

            return;
        }

        if ($data === 'menu:inv') {
            $this->inventoryScreen($responder, $player);

            return;
        }

        if (preg_match('/^inv:eq:(\d+)$/', $data, $m) === 1) {
            $this->equip($responder, $player, (int) $m[1]);

            return;
        }

        if ($data === 'menu:stats') {
            $this->statsScreen($responder, $player);

            return;
        }

        if (preg_match('/^stat:(STRENGTH|AGILITY|INSTINCT|VITALITY)$/', $data, $m) === 1) {
            $this->spendStat($responder, $player, $m[1]);
        }
    }

    private function inventoryScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->inventory->list($player->tg_id);
        $buttons = [];

        foreach ($rows as $row) {
            if ($row->is_equipped) {
                continue;
            }

            $buttons[] = [[
                'text' => __('menu.equip_item', ['name' => $row->item_name]),
                'callback_data' => 'inv:eq:' . $row->id,
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
        ]];

        $responder->edit(
            $this->inventory->inventoryText($rows),
            ['inline_keyboard' => $buttons],
        );
    }

    private function equip(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->inventory->equip($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof \App\Models\Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('menu.equipped', [
                'name' => $res->item->item_name,
                'profile' => $this->characters->profileText($res->character),
            ]),
            TelegramKeyboards::mainMenu(),
        );
    }

    private function statsScreen(TelegramResponder $responder, Character $player): void
    {
        if ($player->stat_points <= 0) {
            $responder->edit(__('profile.no_free_points'), TelegramKeyboards::mainMenu());

            return;
        }

        $responder->edit(
            __('profile.stats_screen', [
                'points' => $player->stat_points,
                'str' => $player->strength,
                'agi' => $player->agility,
                'inst' => $player->instinct,
                'vit' => $player->vitality,
            ]),
            TelegramKeyboards::statsUpgrade(),
        );
    }

    private function spendStat(TelegramResponder $responder, Character $player, string $stat): void
    {
        $res = $this->characters->spendStatPoint($player, $stat);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        if ($res->character->stat_points > 0) {
            $responder->edit(
                __('profile.stats_left', [
                    'points' => $res->character->stat_points,
                    'str' => $res->character->strength,
                    'agi' => $res->character->agility,
                    'inst' => $res->character->instinct,
                    'vit' => $res->character->vitality,
                ]),
                TelegramKeyboards::statsUpgrade(),
            );

            return;
        }

        $responder->edit(
            __('profile.stats_done', [
                'profile' => $this->characters->profileText($res->character),
            ]),
            TelegramKeyboards::mainMenu(),
        );
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

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);

            return null;
        }

        return $player;
    }
}
