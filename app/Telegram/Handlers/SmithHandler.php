<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackRepairAction;
use App\Actions\Backpack\BackpackRepairAllAction;
use App\Actions\Backpack\BackpackRepairVipAction;
use App\Actions\Bag\BagGemBuyAction;
use App\Actions\Bag\BagGemSocketAction;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\RepairService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class SmithHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly RepairService $repairs,
        private readonly BackpackRepairAction $repair,
        private readonly BackpackRepairAllAction $repairAll,
        private readonly BackpackRepairVipAction $repairVip,
        private readonly BagGemBuyAction $buyGem,
        private readonly BagGemSocketAction $socketGem,
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

        if ($data === 'menu:smith') {
            $this->smithScreen($responder, $player);

            return;
        }

        if ($data === 'smith:repair_all') {
            $this->smithRepairAll($responder, $player);

            return;
        }

        if ($data === 'smith:vip') {
            $this->smithVipScreen($responder, $player);

            return;
        }

        if (preg_match('/^smith:repair:(\d+)$/', $data, $m) === 1) {
            $this->smithRepair($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^smith:vip:repair:(\d+)$/', $data, $m) === 1) {
            $this->smithVipRepair($responder, $player, (int) $m[1]);

            return;
        }

        if ($data === 'smith:gems') {
            $this->smithGemsScreen($responder, $player);

            return;
        }

        if ($data === 'smith:gems:socket') {
            $this->smithSocketPickItem($responder, $player);

            return;
        }

        if (preg_match('/^smith:gems:buy:([a-z0-9_]+)$/', $data, $m) === 1) {
            $this->smithBuyGem($responder, $player, $m[1]);

            return;
        }

        if (preg_match('/^smith:gems:socket:(\d+)$/', $data, $m) === 1) {
            $this->smithSocketPickGem($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^smith:gems:socket:(\d+):(\d+)$/', $data, $m) === 1) {
            $this->smithSocket($responder, $player, (int) $m[1], (int) $m[2]);
        }
    }

    public function showRepair(TelegramResponder $responder, Character $player): void
    {
        $this->smithScreen($responder, $player);
    }

    private function pouchText(Character $player): string
    {
        $parts = [];

        foreach ($this->bag->looseGems($player) as $row) {
            if (! $this->bagCatalog->hasGem($row->catalog_id)) {
                continue;
            }

            $def = $this->bagCatalog->findGem($row->catalog_id);
            $parts[] = __('smith.gems_pouch_row', [
                'name' => $def->name,
                'current' => $row->durability,
                'max' => $def->maxDurability,
            ]);
        }

        if ($parts === []) {
            return __('smith.gems_pouch_empty');
        }

        return implode('; ', $parts);
    }

    private function smithBuyGem(TelegramResponder $responder, Character $player, string $catalogId): void
    {
        $res = $this->buyGem->handle($player, $catalogId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.bought_gem', [
                'name' => $this->bagCatalog->findGem($catalogId)->name,
            ]),
            TelegramKeyboards::smithGemsNav(),
        );
    }

    private function smithGemsScreen(TelegramResponder $responder, Character $player): void
    {
        $buttons = [];

        foreach ($this->bagCatalog->shopGems() as $gem) {
            $buttons[] = [[
                'text' => __('smith.buy_gem_btn', [
                    'name' => $gem->name,
                    'price' => $gem->price,
                    'mark' => $gem->currency->telegramMark(),
                ]),
                'callback_data' => 'smith:gems:buy:' . $gem->id,
            ]];
        }

        $buttons[] = [[
            'text' => __('smith.socket_btn'),
            'callback_data' => 'smith:gems:socket',
        ]];
        $buttons[] = [[
            'text' => __('menu.smith'),
            'callback_data' => 'menu:smith',
        ]];

        $responder->edit(
            __('smith.gems_title', [
                'silver' => $player->silver,
                'gold' => $player->gold,
                'pouch' => $this->pouchText($player),
            ]),
            TelegramKeyboards::custom($buttons),
        );
    }

    private function smithRepair(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->repair->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.repaired', [
                'name' => $res->item->item_name,
                'silver' => $res->character->silver,
            ]),
            TelegramKeyboards::smithBack(),
        );
    }

    private function smithRepairAll(TelegramResponder $responder, Character $player): void
    {
        $res = $this->repairAll->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.repaired_all', [
                'gold' => $res->character->gold,
            ]),
            TelegramKeyboards::smithBack(),
        );
    }

    private function smithScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->repairs->damagedList($player->tg_id);
        $buttons = [];

        foreach ($rows as $row) {
            $cost = $this->repairs->repairCost($row);
            $buttons[] = [[
                'text' => __('smith.repair_btn', [
                    'name' => $row->item_name,
                    'current' => $row->durability,
                    'max' => $row->max_durability,
                    'price' => $cost,
                ]),
                'callback_data' => 'smith:repair:' . $row->id,
            ]];
        }

        if (! $rows->isEmpty()) {
            $buttons[] = [[
                'text' => __('smith.repair_all_btn', [
                    'price' => $this->repairs->repairAllGoldCost($player),
                ]),
                'callback_data' => 'smith:repair_all',
            ]];
        }

        $buttons[] = [[
            'text' => __('smith.vip_btn'),
            'callback_data' => 'smith:vip',
        ]];
        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];
        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
            'style' => 'danger',
        ]];

        if ($rows->isEmpty()) {
            $text = __('smith.empty', [
                'silver' => $player->silver,
                'gold' => $player->gold,
            ]);
        } else {
            $text = __('smith.title', [
                'silver' => $player->silver,
                'gold' => $player->gold,
            ]);
        }

        $responder->edit($text, TelegramKeyboards::custom($buttons));
    }

    private function smithVipRepair(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->repairVip->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.vip_repaired', [
                'name' => $res->item->item_name,
                'silver' => $res->character->silver,
                'gold' => $res->character->gold,
            ]),
            TelegramKeyboards::smithVipNav(),
        );
    }

    private function smithVipScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->repairs->damagedVipList($player->tg_id);
        $buttons = [];
        $goldPass = $this->repairs->repairVipGoldPass();
        $hasPremium = $this->characters->hasActivePremium($player);

        foreach ($rows as $row) {
            $silver = $this->repairs->repairVipSilverCost($row);

            if ($hasPremium) {
                $btn = __('smith.vip_repair_btn_premium', [
                    'name' => $row->item_name,
                    'current' => $row->durability,
                    'max' => $row->max_durability,
                    'silver' => $silver,
                ]);
            } else {
                $btn = __('smith.vip_repair_btn', [
                    'name' => $row->item_name,
                    'current' => $row->durability,
                    'max' => $row->max_durability,
                    'silver' => $silver,
                    'gold' => $goldPass,
                ]);
            }

            $buttons[] = [[
                'text' => $btn,
                'callback_data' => 'smith:vip:repair:' . $row->id,
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.smith'),
            'callback_data' => 'menu:smith',
        ]];

        if ($hasPremium) {
            $access = __('smith.vip_access_premium');
        } else {
            $access = __('smith.vip_access_gold', ['gold' => $goldPass]);
        }

        if ($rows->isEmpty()) {
            $text = __('smith.vip_empty', [
                'silver' => $player->silver,
                'gold' => $player->gold,
                'access' => $access,
            ]);
        } else {
            $text = __('smith.vip_title', [
                'silver' => $player->silver,
                'gold' => $player->gold,
                'access' => $access,
            ]);
        }

        $responder->edit($text, TelegramKeyboards::custom($buttons));
    }

    private function smithSocket(TelegramResponder $responder, Character $player, int $backpackItemId, int $bagItemId): void
    {
        $res = $this->socketGem->handle($player, $backpackItemId, $bagItemId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.socketed', ['name' => $res->item->item_name]),
            TelegramKeyboards::smithGemsNav(),
        );
    }

    private function smithSocketPickGem(TelegramResponder $responder, Character $player, int $backpackItemId): void
    {
        $item = BackpackItem::query()
            ->where('id', $backpackItemId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($item === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $buttons = [];

        foreach ($this->bag->looseGems($player) as $gem) {
            if (! $this->bagCatalog->gemInCatalog($gem->catalog_id)) {
                continue;
            }

            $def = $this->bagCatalog->findGem($gem->catalog_id);

            if (! $def->enabled) {
                continue;
            }

            $buttons[] = [[
                'text' => __('smith.socket_gem_btn', [
                    'name' => $def->name,
                    'current' => $gem->durability,
                    'max' => $def->maxDurability,
                ]),
                'callback_data' => 'smith:gems:socket:' . $item->id . ':' . $gem->id,
            ]];
        }

        if ($buttons === []) {
            $responder->edit(
                __('smith.no_pouch_gems'),
                TelegramKeyboards::smithOnly(),
            );

            return;
        }

        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];

        $responder->edit(
            __('smith.socket_pick_gem', ['name' => $item->item_name]),
            TelegramKeyboards::custom($buttons),
        );
    }

    private function smithSocketPickItem(TelegramResponder $responder, Character $player): void
    {
        $buttons = [];

        foreach ($this->backpack->list($player->tg_id) as $row) {
            $free = $this->bag->freeSocketCount($row);

            if ($free <= 0) {
                continue;
            }

            $buttons[] = [[
                'text' => __('smith.socket_item_btn', [
                    'name' => $row->item_name,
                    'free' => $free,
                ]),
                'callback_data' => 'smith:gems:socket:' . $row->id,
            ]];
        }

        if ($buttons === []) {
            $responder->edit(
                __('smith.no_socket_targets'),
                TelegramKeyboards::smithOnly(),
            );

            return;
        }

        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];

        $responder->edit(__('smith.socket_pick_item'), TelegramKeyboards::custom($buttons));
    }
}
