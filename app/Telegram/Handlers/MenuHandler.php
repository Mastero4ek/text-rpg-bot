<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackDiscardAction;
use App\Actions\Backpack\BackpackRepairAction;
use App\Actions\Backpack\BackpackRepairAllAction;
use App\Actions\Backpack\BackpackRepairVipAction;
use App\Actions\Bag\BagGemBuyAction;
use App\Actions\Bag\BagGemDiscardAction;
use App\Actions\Bag\BagGemSocketAction;
use App\Actions\Character\CharacterResetStatsForGoldAction;
use App\Actions\Character\CharacterSpendStatPointAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Enums\ProgressStepEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Backpack\RepairService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\Fight\FightService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Registration\RegistrationFlow;
use App\Services\Shop\ShopCatalog;
use App\Support\Gem\GemMfText;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class MenuHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly FightService $fights,
        private readonly BackpackService $backpack,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly LoadoutService $loadout,
        private readonly RepairService $repairs,
        private readonly OnboardingService $onboarding,
        private readonly RegistrationFlow $registration,
        private readonly BackpackRepairAction $repair,
        private readonly BackpackRepairAllAction $repairAll,
        private readonly BackpackRepairVipAction $repairVip,
        private readonly BackpackDiscardAction $discardItemAction,
        private readonly BagGemBuyAction $buyGem,
        private readonly BagGemDiscardAction $discardGemAction,
        private readonly BagGemSocketAction $socketGem,
        private readonly CharacterSpendStatPointAction $spendStatPoint,
        private readonly CharacterResetStatsForGoldAction $resetStatsForGold,
        private readonly ShopCatalog $shop,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $this->canonicalCallback($update->callbackData());
        $responder->answerCallback();
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($data === 'menu:profile') {
            $responder->edit($this->characters->profileText($player), TelegramKeyboards::backToCity());

            return;
        }

        if ($data === 'inv:hub') {
            $responder->edit(__('menu.inventory'), TelegramKeyboards::inventoryHub());

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

            return;
        }

        $slotPattern = SlotEnum::gameplayEquipSlotPattern();

        if (preg_match('/^gear:uneq:(' . $slotPattern . ')$/', $data, $m) === 1) {
            $slot = SlotEnum::from($m[1]);
            $res = $this->loadout->unequipSlot($player, $slot);

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

        if ($data === 'menu:inv' || $data === 'inv:filter:all') {
            $this->backpackScreen($responder, $player, null);

            return;
        }

        if ($data === 'menu:bag' || $data === 'bag:filter:gems') {
            $this->bagScreen($responder, $player);

            return;
        }

        if (preg_match('/^inv:filter:(WEAPON|ARMOR|JEWELRY)$/', $data, $m) === 1) {
            $this->backpackScreen($responder, $player, TypeEnum::from($m[1]));

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

        if (preg_match('/^inv:discard:(\d+)$/', $data, $m) === 1) {
            $this->discardConfirmScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^inv:discard_yes:(\d+)$/', $data, $m) === 1) {
            $this->runDiscardItem($responder, $player, (int) $m[1]);

            return;
        }

        if ($data === 'bag:filter:potions') {
            $this->bagScreen($responder, $player);

            return;
        }

        if (preg_match('/^bag:gem:(\d+)$/', $data, $m) === 1) {
            $this->bagGemCardScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^bag:potion:(\d+)$/', $data, $m) === 1) {
            $this->bagPotionCardScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^bag:discard:(\d+)$/', $data, $m) === 1) {
            $this->bagDiscardConfirmScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^bag:discard_yes:(\d+)$/', $data, $m) === 1) {
            $this->runDiscardBagItem($responder, $player, (int) $m[1]);

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

    public function handleText(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $player = $this->requireDone($update, $responder);

        if (! $player instanceof Character) {
            return;
        }

        if ($this->fights->exists($player->tg_id)) {
            return;
        }

        $text = $update->text();

        if ($text === __('menu.profile')) {
            $responder->reply($this->characters->profileText($player), TelegramKeyboards::backToCity());

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

    public function showSmith(TelegramResponder $responder, Character $player): void
    {
        $this->smithScreen($responder, $player);
    }

    private function canonicalCallback(string $data): string
    {
        if (str_starts_with($data, 'menu:backpack')) {
            return 'menu:inv' . mb_substr($data, mb_strlen('menu:backpack'));
        }

        if (str_starts_with($data, 'backpack:')) {
            return 'inv:' . mb_substr($data, mb_strlen('backpack:'));
        }

        return $data;
    }

    private function equip(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->loadout->equip($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
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
        $res = $this->loadout->equipToSlot($player, $rowId, $slot);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->gearScreen($responder, $res->character);
    }

    private function gearPickScreen(TelegramResponder $responder, Character $player, SlotEnum $slot): void
    {
        $rows = $this->backpack->list($player->tg_id);
        $buttons = [];
        $hasCandidate = false;

        foreach ($rows as $row) {
            if (! $this->shop->hasItem($row->catalog_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->catalog_id);

            if (! $this->loadout->fitsEquipSlot($player, $def, $slot)) {
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

            if ($row instanceof BackpackItem) {
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
            'text' => __('menu.backpack'),
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

    private function backpackScreen(TelegramResponder $responder, Character $player, ?TypeEnum $type): void
    {
        $rows = $this->backpack->listByType($player->tg_id, $type);
        $current = $this->backpack->rowCount($player->tg_id);
        $max = $this->backpack->maxRows($player);
        $buttons = [];

        foreach ($rows as $row) {
            $buttons[] = [[
                'text' => $this->backpack->rowLabel($row),
                'callback_data' => 'inv:card:' . $row->id,
            ]];
        }

        $buttons[] = [
            ['text' => __('menu.backpack_filter_all'), 'callback_data' => 'inv:filter:all'],
            ['text' => TypeEnum::WEAPON->getLabel(), 'callback_data' => 'inv:filter:WEAPON'],
        ];
        $buttons[] = [
            ['text' => TypeEnum::ARMOR->getLabel(), 'callback_data' => 'inv:filter:ARMOR'],
            ['text' => TypeEnum::JEWELRY->getLabel(), 'callback_data' => 'inv:filter:JEWELRY'],
        ];
        $buttons[] = [[
            'text' => __('menu.bag'),
            'callback_data' => 'menu:bag',
        ]];
        $buttons[] = [[
            'text' => __('menu.gear'),
            'callback_data' => 'menu:gear',
        ]];
        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
        ]];

        if ($type instanceof TypeEnum) {
            $header = __('profile.backpack_header', [
                'current' => $current,
                'max' => $max,
                'filter' => $type->getLabel(),
            ]);
        } else {
            $header = __('profile.backpack_header_all', [
                'current' => $current,
                'max' => $max,
            ]);
        }

        if ($rows->isEmpty()) {
            if ($type instanceof TypeEnum) {
                $body = __('profile.inventory_empty_type');
            } else {
                $body = __('profile.inventory_empty');
            }
        } else {
            $body = $this->backpack->backpackText($rows);
        }

        $responder->edit($header . "\n\n" . $body, ['inline_keyboard' => $buttons]);
    }

    private function bagScreen(TelegramResponder $responder, Character $player): void
    {
        $potions = $this->bag->loosePotions($player);
        $gems = $this->bag->looseGems($player);
        $buttons = [];

        foreach ($potions as $row) {
            if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                continue;
            }

            $def = $this->bagCatalog->findPotion($row->catalog_id);
            $buttons[] = [[
                'text' => __('profile.bag_potion_row', [
                    'name' => $def->name,
                    'quantity' => $row->quantity,
                    'max' => $this->bag->potionMaxStack(),
                ]),
                'callback_data' => 'bag:potion:' . $row->id,
            ]];
        }

        foreach ($gems as $row) {
            if (! $this->bagCatalog->hasGem($row->catalog_id)) {
                $label = $row->catalog_id . ' (' . $row->durability . ')';
            } else {
                $def = $this->bagCatalog->findGem($row->catalog_id);
                $label = __('profile.bag_gem_row', [
                    'name' => $def->name,
                    'current' => $row->durability,
                    'max' => $def->maxDurability,
                ]);
            }

            $buttons[] = [[
                'text' => $label,
                'callback_data' => 'bag:gem:' . $row->id,
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.to_smith'),
            'callback_data' => 'smith:gems',
        ]];
        $buttons[] = [[
            'text' => __('menu.backpack'),
            'callback_data' => 'menu:inv',
        ]];
        $buttons[] = [[
            'text' => __('menu.back'),
            'callback_data' => 'menu:home',
        ]];

        $count = $this->bag->bagRowCount($player);
        $header = __('profile.bag_header', [
            'count' => $count,
        ]);

        if ($count === 0) {
            $text = $header . "\n\n" . __('profile.bag_empty');
        } else {
            $text = $header;
        }

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function itemCardScreen(
        TelegramResponder $responder,
        Character $player,
        int $rowId,
        ?SlotEnum $pickSlot,
    ): void {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if (! $this->shop->hasItem($row->catalog_id)) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $def = $this->shop->findItem($row->catalog_id);
        $text = $this->loadout->itemCardText($def);

        if ($row->max_durability !== null && $row->durability !== null) {
            $text .= "\n" . __('profile.item_card_durability', [
                'current' => $row->durability,
                'max' => $row->max_durability,
            ]);
        }

        if ($this->bag->gemSlotCount($row) > 0) {
            $text .= "\n" . __('profile.item_card_gems', [
                'value' => $this->bag->socketedText($row),
            ]);
        }

        $buttons = [];

        if ($row->isEquipped()) {
            $buttons[] = [[
                'text' => __('menu.unequip_item', ['name' => $row->item_name]),
                'callback_data' => 'inv:uneq:' . $row->id,
            ]];
        } elseif ($this->shop->isEquippable($row->catalog_id)) {
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

        if (! $row->isEquipped()) {
            $buttons[] = [[
                'text' => __('menu.discard_item'),
                'callback_data' => 'inv:discard:' . $row->id,
            ]];
        }

        if ($pickSlot instanceof SlotEnum) {
            $buttons[] = [[
                'text' => __('menu.back_to_slot', ['slot' => $pickSlot->getLabel()]),
                'callback_data' => 'gear:pick:' . $pickSlot->value,
            ]];
        } else {
            $buttons[] = [[
                'text' => __('menu.backpack'),
                'callback_data' => 'menu:inv',
            ]];
        }

        $buttons[] = [[
            'text' => __('menu.gear'),
            'callback_data' => 'menu:gear',
        ]];

        $responder->edit($text, ['inline_keyboard' => $buttons]);
    }

    private function bagDiscardConfirmScreen(TelegramResponder $responder, Character $player, int $bagItemId): void
    {
        $row = $this->looseBagItem($player, $bagItemId);

        if (! $row instanceof BagItem) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $name = $this->bagItemName($row);

        $responder->edit(
            __('profile.discard_gem_confirm', ['name' => $name]),
            ['inline_keyboard' => [
                [[
                    'text' => __('menu.discard_confirm_yes'),
                    'callback_data' => 'bag:discard_yes:' . $row->id,
                ]],
                [[
                    'text' => __('menu.bag'),
                    'callback_data' => 'menu:bag',
                ]],
            ]],
        );
    }

    private function bagGemCardScreen(TelegramResponder $responder, Character $player, int $bagItemId): void
    {
        $row = $this->looseBagItem($player, $bagItemId);

        if (! $row instanceof BagItem || $row->kind !== BagKindEnum::GEM) {
            $responder->reply(__('errors.gem_not_in_pouch'), null);

            return;
        }

        if (! $this->bagCatalog->hasGem($row->catalog_id)) {
            $responder->reply(__('errors.gem_not_found'), null);

            return;
        }

        $def = $this->bagCatalog->findGem($row->catalog_id);
        $text = __('profile.bag_gem_card', [
            'name' => $def->name,
            'type' => $def->type->getLabel(),
            'mf' => GemMfText::forDef($def),
            'current' => $row->durability,
            'max' => $def->maxDurability,
        ]);

        $responder->edit($text, ['inline_keyboard' => [
            [[
                'text' => __('menu.discard_item'),
                'callback_data' => 'bag:discard:' . $row->id,
            ]],
            [[
                'text' => __('menu.to_smith'),
                'callback_data' => 'smith:gems',
            ]],
            [[
                'text' => __('menu.bag'),
                'callback_data' => 'menu:bag',
            ]],
        ]]);
    }

    private function bagPotionCardScreen(TelegramResponder $responder, Character $player, int $bagItemId): void
    {
        $row = $this->looseBagItem($player, $bagItemId);

        if (! $row instanceof BagItem || $row->kind !== BagKindEnum::POTION) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $def = $this->bagCatalog->findPotion($row->catalog_id);
        $text = __('profile.bag_potion_card', [
            'name' => $def->name,
            'quantity' => $row->quantity,
            'max' => $this->bag->potionMaxStack(),
            'effect' => $def->effectValue,
        ]);

        $responder->edit($text, ['inline_keyboard' => [
            [[
                'text' => __('menu.discard_item'),
                'callback_data' => 'bag:discard:' . $row->id,
            ]],
            [[
                'text' => __('menu.bag'),
                'callback_data' => 'menu:bag',
            ]],
        ]]);
    }

    private function discardConfirmScreen(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if ($row->isEquipped()) {
            $responder->reply(__('errors.unequip_first'), null);

            return;
        }

        $hasGems = $this->bag->socketedInstances($row)->isNotEmpty();

        if ($hasGems) {
            $text = __('profile.discard_confirm', ['name' => $this->backpack->rowLabel($row)]);
        } else {
            $text = __('profile.discard_confirm_plain', ['name' => $this->backpack->rowLabel($row)]);
        }

        $responder->edit($text, ['inline_keyboard' => [
            [[
                'text' => __('menu.discard_confirm_yes'),
                'callback_data' => 'inv:discard_yes:' . $row->id,
            ]],
            [[
                'text' => __('menu.backpack'),
                'callback_data' => 'inv:card:' . $row->id,
            ]],
        ]]);
    }

    private function runDiscardBagItem(TelegramResponder $responder, Character $player, int $bagItemId): void
    {
        $row = $this->looseBagItem($player, $bagItemId);

        if (! $row instanceof BagItem) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $name = $this->bagItemName($row);
        $res = $this->discardGemAction->handle($player, $bagItemId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->reply(__('profile.discarded', ['name' => $name]), null);
        $this->bagScreen($responder, $res->character);
    }

    private function runDiscardItem(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $name = $row->item_name;
        $res = $this->discardItemAction->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $responder->reply(__('profile.discarded', ['name' => $name]), null);
        $this->backpackScreen($responder, $res->character, null);
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
            ['inline_keyboard' => $buttons],
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
            ['inline_keyboard' => [[
                ['text' => __('smith.vip_btn'), 'callback_data' => 'smith:vip'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
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

        $responder->edit($text, ['inline_keyboard' => $buttons]);
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
            ['inline_keyboard' => [[
                ['text' => __('smith.gems_btn'), 'callback_data' => 'smith:gems'],
            ], [
                ['text' => __('menu.smith'), 'callback_data' => 'menu:smith'],
            ]]],
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
            __('smith.socket_pick_gem', ['name' => $item->item_name]),
            ['inline_keyboard' => $buttons],
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

    private function bagItemName(BagItem $row): string
    {
        if ($row->kind === BagKindEnum::GEM) {
            if ($this->bagCatalog->hasGem($row->catalog_id)) {
                return $this->bagCatalog->findGem($row->catalog_id)->name;
            }

            return $row->catalog_id;
        }

        if ($this->bagCatalog->hasPotion($row->catalog_id)) {
            return $this->bagCatalog->findPotion($row->catalog_id)->name;
        }

        return $row->catalog_id;
    }

    private function looseBagItem(Character $player, int $bagItemId): ?BagItem
    {
        return BagItem::query()
            ->where('id', $bagItemId)
            ->where('tg_id', $player->tg_id)
            ->whereNull('backpack_item_id')
            ->first();
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
        $player = $this->loadout->dropUnmetEquipped($player);

        if ($this->registration->isActive($player)) {
            $this->registration->showNudge($responder, $player);

            return null;
        }

        if ($player->progress_step !== ProgressStepEnum::DONE) {
            $responder->reply($this->onboarding->stepHint($player->progress_step->value), null);

            return null;
        }

        return $player;
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

    private function unequip(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $res = $this->loadout->unequip($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $this->gearScreen($responder, $res->character);
    }
}
