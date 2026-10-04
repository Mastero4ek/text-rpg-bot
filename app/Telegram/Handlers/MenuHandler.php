<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Gem\GemBuyAction;
use App\Actions\Gem\GemBuyWardAction;
use App\Actions\Gem\GemSocketAction;
use App\Actions\Gem\GemUnsocketAction;
use App\Actions\Inventory\InventoryRepairAction;
use App\Actions\Inventory\InventoryRepairAllAction;
use App\Actions\Inventory\InventoryRepairVipAction;
use App\Enums\Equipment\SlotEnum;
use App\Enums\OnboardingStepEnum;
use App\Models\Character;
use App\Models\Inventory;
use App\Services\Character\CharacterService;
use App\Services\Gem\GemCatalog;
use App\Services\Gem\GemService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\LoadoutService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Shop\ShopCatalog;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class MenuHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly InventoryService $inventory,
        private readonly GemService $gemService,
        private readonly GemCatalog $gemCatalog,
        private readonly LoadoutService $loadout,
        private readonly OnboardingService $onboarding,
        private readonly InventoryRepairAction $repair,
        private readonly InventoryRepairAllAction $repairAll,
        private readonly InventoryRepairVipAction $repairVip,
        private readonly GemBuyAction $buyGem,
        private readonly GemBuyWardAction $buyGemWard,
        private readonly GemSocketAction $socketGem,
        private readonly GemUnsocketAction $unsocketGem,
        private readonly ShopCatalog $shop,
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

        if ($data === 'menu:gear') {
            $this->gearScreen($responder, $player);

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

        if ($data === 'smith:gems:ward') {
            $this->smithBuyWard($responder, $player);

            return;
        }

        if ($data === 'smith:gems:socket') {
            $this->smithSocketPickItem($responder, $player);

            return;
        }

        if ($data === 'smith:gems:unsocket') {
            $this->smithUnsocketPickItem($responder, $player);

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

            return;
        }

        if (preg_match('/^smith:gems:unsocket:(\d+)$/', $data, $m) === 1) {
            $this->smithUnsocketPickSlot($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^smith:gems:unsocket:(\d+):(\d+)$/', $data, $m) === 1) {
            $this->smithUnsocket($responder, $player, (int) $m[1], (int) $m[2]);

            return;
        }

        $slotPattern = SlotEnum::gameplayEquipSlotPattern();

        if (preg_match('/^gear:uneq:(' . $slotPattern . ')$/', $data, $m) === 1) {
            $slot = SlotEnum::from($m[1]);
            $res = $this->inventory->unequipSlot($player, $slot);

            if (! $res->ok || ! $res->character instanceof Character) {
                $responder->reply(TelegramResponder::errorMessage($res->error), null);

                return;
            }

            $this->gearScreen($responder, $res->character);

            return;
        }

        if (preg_match('/^gear:pick:(' . $slotPattern . ')$/', $data, $m) === 1) {
            $this->gearPickScreen($responder, $player, SlotEnum::from($m[1]));

            return;
        }

        if ($data === 'menu:inv') {
            $this->inventoryScreen($responder, $player);

            return;
        }

        if (preg_match('/^inv:card:(\d+)(?::pick:(' . $slotPattern . '))?$/', $data, $m) === 1) {
            if (isset($m[2])) {
                $pickSlot = SlotEnum::from($m[2]);
            } else {
                $pickSlot = null;
            }

            $this->itemCardScreen($responder, $player, (int) $m[1], $pickSlot);

            return;
        }

        if (preg_match('/^inv:eq:(\d+)(?::(' . $slotPattern . '))?$/', $data, $m) === 1) {
            if (isset($m[2])) {
                $this->equipToSlot($responder, $player, (int) $m[1], SlotEnum::from($m[2]));
            } else {
                $this->equip($responder, $player, (int) $m[1]);
            }

            return;
        }

        if (preg_match('/^inv:uneq:(\d+)$/', $data, $m) === 1) {
            $this->unequip($responder, $player, (int) $m[1]);

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

    private function equip(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->inventory->equip($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->gearScreen($responder, $res->character);
    }

    private function equipToSlot(
        TelegramResponder $responder,
        Character $player,
        int $rowId,
        SlotEnum $slot,
    ): void {
        $res = $this->inventory->equipToSlot($player, $rowId, $slot);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->gearScreen($responder, $res->character);
    }

    private function gearPickScreen(TelegramResponder $responder, Character $player, SlotEnum $slot): void
    {
        $rows = $this->inventory->list($player->tg_id);
        $buttons = [];
        $hasCandidate = false;

        foreach ($rows as $row) {
            if ($row->is_equipped) {
                continue;
            }

            if (! $this->shop->hasItem($row->item_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->item_id);

            if (! $this->inventory->fitsEquipSlot($player, $def, $slot)) {
                continue;
            }

            $hasCandidate = true;
            $buttons[] = [[
                'text' => $row->item_name,
                'callback_data' => 'inv:card:' . $row->id . ':pick:' . $slot->value,
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.gear'),
            'callback_data' => 'menu:gear',
        ]];
        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
        ]];

        if ($hasCandidate) {
            $text = __('menu.gear_pick_title', ['slot' => $slot->getLabel()]);
        } else {
            $text = __('menu.gear_pick_empty', ['slot' => $slot->getLabel()]);
        }

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function gearScreen(TelegramResponder $responder, Character $player): void
    {
        $loadout = $this->loadout->forCharacter($player);
        $buttons = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $row = $loadout->row($slot);

            if ($row instanceof Inventory) {
                $buttons[] = [[
                    'text' => __('menu.view_slot_item', [
                        'slot' => $slot->getLabel(),
                        'name' => $row->item_name,
                    ]),
                    'callback_data' => 'inv:card:' . $row->id,
                ]];
                $buttons[] = [[
                    'text' => __('menu.unequip_slot', ['slot' => $slot->getLabel()]),
                    'callback_data' => 'gear:uneq:' . $slot->value,
                ]];
            } else {
                $buttons[] = [[
                    'text' => __('menu.pick_for_slot', ['slot' => $slot->getLabel()]),
                    'callback_data' => 'gear:pick:' . $slot->value,
                ]];
            }
        }

        $buttons[] = [[
            'text' => __('menu.inventory'),
            'callback_data' => 'menu:inv',
        ]];
        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
        ]];

        if ($player->username === null) {
            $name = __('common.unnamed');
        } else {
            $name = $player->username;
        }

        $text = __('profile.gear_header', [
            'name' => $name,
            'level' => $player->level,
            'hp' => $player->current_hp,
            'maxHp' => $this->characters->maxHp($player),
            'silver' => $player->silver,
        ]) . "\n\n" . $this->loadout->gearText($loadout);

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function inventoryScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->inventory->list($player->tg_id);
        $buttons = [];

        foreach ($rows as $row) {
            if ($row->is_equipped) {
                $mark = '✅ ';
            } else {
                $mark = '';
            }

            $buttons[] = [[
                'text' => $mark . $row->item_name,
                'callback_data' => 'inv:card:' . $row->id,
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.gear'),
            'callback_data' => 'menu:gear',
        ]];
        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
        ]];

        $responder->edit(
            $this->inventory->inventoryText($rows),
            ['inline_keyboard' => $buttons],
        );
    }

    private function itemCardScreen(
        TelegramResponder $responder,
        Character $player,
        int $rowId,
        ?SlotEnum $pickSlot,
    ): void {
        $row = Inventory::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if (! $this->shop->hasItem($row->item_id)) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $def = $this->shop->findItem($row->item_id);
        $text = $this->loadout->itemCardText($def);

        if ($row->max_durability !== null && $row->durability !== null) {
            $text .= "\n" . __('profile.item_card_durability', [
                'current' => $row->durability,
                'max' => $row->max_durability,
            ]);
        }

        if ($this->gemService->gemSlotCount($row) > 0) {
            $text .= "\n" . __('profile.item_card_gems', [
                'value' => $this->gemService->socketedText($row),
            ]);
        }

        $buttons = [];

        if ($row->is_equipped) {
            $buttons[] = [[
                'text' => __('menu.unequip_item', ['name' => $row->item_name]),
                'callback_data' => 'inv:uneq:' . $row->id,
            ]];
        } elseif ($this->shop->isEquippable($row->item_id)) {
            if ($pickSlot instanceof SlotEnum) {
                $buttons[] = [[
                    'text' => __('menu.wear_item'),
                    'callback_data' => 'inv:eq:' . $row->id . ':' . $pickSlot->value,
                ]];
            } else {
                $buttons[] = [[
                    'text' => __('menu.wear_item'),
                    'callback_data' => 'inv:eq:' . $row->id,
                ]];
            }
        }

        if ($pickSlot instanceof SlotEnum) {
            $buttons[] = [[
                'text' => __('menu.back_to_slot', ['slot' => $pickSlot->getLabel()]),
                'callback_data' => 'gear:pick:' . $pickSlot->value,
            ]];
        } else {
            $buttons[] = [[
                'text' => __('menu.inventory'),
                'callback_data' => 'menu:inv',
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.gear'),
            'callback_data' => 'menu:gear',
        ]];

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function pouchText(Character $player): string
    {
        $pouch = $this->gemService->pouch($player);
        $parts = [];

        foreach ($pouch as $instance) {
            if (! $this->gemCatalog->has($instance['gem_id'])) {
                continue;
            }

            $def = $this->gemCatalog->find($instance['gem_id']);
            $parts[] = __('smith.gems_pouch_row', [
                'name' => $def->name,
                'current' => $instance['durability'],
                'max' => $def->maxDurability,
            ]);
        }

        if ($parts === []) {
            return __('smith.gems_pouch_empty');
        }

        return implode('; ', $parts);
    }

    private function smithBuyGem(TelegramResponder $responder, Character $player, string $gemId): void
    {
        $res = $this->buyGem->handle($player, $gemId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.bought_gem', [
                'name' => $this->gemCatalog->find($gemId)->name,
            ]),
            ['inline_keyboard' => [[
                ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
        );
    }

    private function smithBuyWard(TelegramResponder $responder, Character $player): void
    {
        $res = $this->buyGemWard->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.bought_ward', [
                'charges' => $res->character->gem_ward_charges,
                'gold' => $res->character->gold,
            ]),
            ['inline_keyboard' => [[
                ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
        );
    }

    private function smithGemsScreen(TelegramResponder $responder, Character $player): void
    {
        $buttons = [];

        foreach ($this->gemCatalog->shopGems() as $gem) {
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
            'text' => __('smith.buy_ward_btn', [
                'price' => $this->gemCatalog->wardGold(),
            ]),
            'callback_data' => 'smith:gems:ward',
        ]];
        $buttons[] = [[
            'text' => __('smith.socket_btn'),
            'callback_data' => 'smith:gems:socket',
        ]];
        $buttons[] = [[
            'text' => __('smith.unsocket_btn'),
            'callback_data' => 'smith:gems:unsocket',
        ]];
        $buttons[] = [[
            'text' => __('menu.smith'),
            'callback_data' => 'menu:smith',
        ]];

        $responder->edit(
            __('smith.gems_title', [
                'silver' => $player->silver,
                'gold' => $player->gold,
                'ward' => $player->gem_ward_charges,
                'pouch' => $this->pouchText($player),
            ]),
            ['inline_keyboard' => $buttons],
        );
    }

    private function smithRepair(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->repair->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.repaired', [
                'name' => $res->item->item_name,
                'silver' => $res->character->silver,
            ]),
            ['inline_keyboard' => [[
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ], [
                ['text' => __('menu.back'), 'callback_data' => 'menu:home'],
            ]]],
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
            ['inline_keyboard' => [[
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ], [
                ['text' => __('menu.back'), 'callback_data' => 'menu:home'],
            ]]],
        );
    }

    private function smithScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->inventory->damagedList($player->tg_id);
        $buttons = [];

        foreach ($rows as $row) {
            $cost = $this->inventory->repairCost($row);
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
                    'price' => $this->inventory->repairAllGoldCost($player),
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

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function smithVipRepair(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->repairVip->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.vip_repaired', [
                'name' => $res->item->item_name,
                'silver' => $res->character->silver,
                'gold' => $res->character->gold,
            ]),
            ['inline_keyboard' => [[
                ['text' => __('smith.vip_btn'), 'callback_data' => 'smith:vip'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
        );
    }

    private function smithVipScreen(TelegramResponder $responder, Character $player): void
    {
        $rows = $this->inventory->damagedVipList($player->tg_id);
        $buttons = [];
        $goldPass = $this->inventory->repairVipGoldPass();
        $hasPremium = $this->characters->hasActivePremium($player);

        foreach ($rows as $row) {
            $silver = $this->inventory->repairVipSilverCost($row);

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

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function smithSocket(TelegramResponder $responder, Character $player, int $rowId, int $pouchIndex): void
    {
        $res = $this->socketGem->handle($player, $rowId, $pouchIndex);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.socketed', ['name' => $res->item->item_name]),
            ['inline_keyboard' => [[
                ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
        );
    }

    private function smithSocketPickGem(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = Inventory::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $pouch = $this->gemService->pouch($player);
        $buttons = [];

        foreach ($pouch as $index => $instance) {
            if (! $this->gemCatalog->inCatalog($instance['gem_id'])) {
                continue;
            }

            $def = $this->gemCatalog->find($instance['gem_id']);

            if (! $def->enabled) {
                continue;
            }

            $buttons[] = [[
                'text' => __('smith.socket_gem_btn', [
                    'name' => $def->name,
                    'current' => $instance['durability'],
                    'max' => $def->maxDurability,
                ]),
                'callback_data' => 'smith:gems:socket:' . $row->id . ':' . $index,
            ]];
        }

        if ($buttons === []) {
            $responder->edit(
                __('smith.no_pouch_gems'),
                ['inline_keyboard' => [[
                    ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
                ]]],
            );

            return;
        }

        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];

        $responder->edit(
            __('smith.socket_pick_gem', ['name' => $row->item_name]),
            ['inline_keyboard' => $buttons],
        );
    }

    private function smithSocketPickItem(TelegramResponder $responder, Character $player): void
    {
        $buttons = [];

        foreach ($this->inventory->list($player->tg_id) as $row) {
            $free = $this->gemService->freeSocketCount($row);

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
                ['inline_keyboard' => [[
                    ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
                ]]],
            );

            return;
        }

        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];

        $responder->edit(__('smith.socket_pick_item'), ['inline_keyboard' => $buttons]);
    }

    private function smithUnsocket(TelegramResponder $responder, Character $player, int $rowId, int $socketIndex): void
    {
        $res = $this->unsocketGem->handle($player, $rowId, $socketIndex);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->edit(
            __('smith.unsocketed', ['silver' => $res->character->silver]),
            ['inline_keyboard' => [[
                ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
        );
    }

    private function smithUnsocketPickItem(TelegramResponder $responder, Character $player): void
    {
        $buttons = [];

        foreach ($this->inventory->list($player->tg_id) as $row) {
            $instances = $this->gemService->socketedInstances($row);

            if ($instances === []) {
                continue;
            }

            $buttons[] = [[
                'text' => __('smith.unsocket_item_btn', [
                    'name' => $row->item_name,
                    'gems' => $this->gemService->socketedText($row),
                ]),
                'callback_data' => 'smith:gems:unsocket:' . $row->id,
            ]];
        }

        if ($buttons === []) {
            $responder->edit(
                __('smith.no_unsocket_targets'),
                ['inline_keyboard' => [[
                    ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
                ]]],
            );

            return;
        }

        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];

        $responder->edit(
            __('smith.unsocket_pick_item', [
                'price' => $this->gemCatalog->unsocketSilver(),
            ]),
            ['inline_keyboard' => $buttons],
        );
    }

    private function smithUnsocketPickSlot(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = Inventory::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $instances = $this->gemService->socketedInstances($row);
        $buttons = [];

        foreach ($instances as $index => $instance) {
            if ($this->gemCatalog->has($instance['gem_id'])) {
                $def = $this->gemCatalog->find($instance['gem_id']);
                $label = __('smith.gem_instance', [
                    'name' => $def->name,
                    'current' => $instance['durability'],
                    'max' => $def->maxDurability,
                ]);
            } else {
                $label = $instance['gem_id'];
            }

            $buttons[] = [[
                'text' => __('smith.unsocket_slot_btn', ['name' => $label]),
                'callback_data' => 'smith:gems:unsocket:' . $row->id . ':' . $index,
            ]];
        }

        if ($buttons === []) {
            $responder->edit(
                __('smith.no_unsocket_targets'),
                ['inline_keyboard' => [[
                    ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
                ]]],
            );

            return;
        }

        $buttons[] = [[
            'text' => __('smith.gems_btn'),
            'callback_data' => 'smith:gems',
        ]];

        $responder->edit(
            __('smith.unsocket_pick_slot', ['name' => $row->item_name]),
            ['inline_keyboard' => $buttons],
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
        $player = $this->inventory->dropUnmetEquipped($player);

        if ($player->onboarding_step !== OnboardingStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->onboarding_step->value), null);

            return null;
        }

        return $player;
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

    private function unequip(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->inventory->unequip($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof Inventory) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->gearScreen($responder, $res->character);
    }
}
