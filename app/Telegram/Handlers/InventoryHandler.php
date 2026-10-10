<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackDiscardAction;
use App\Actions\Bag\BagGemDiscardAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CharacterService;
use App\Services\Shop\ShopCatalog;
use App\Support\Gem\GemMfText;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\TelegramKeyboards;

final class InventoryHandler
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly BackpackService $backpack,
        private readonly BagService $bag,
        private readonly BagCatalog $bagCatalog,
        private readonly LoadoutService $loadout,
        private readonly ShopCatalog $shop,
        private readonly BackpackDiscardAction $discardItemAction,
        private readonly BagGemDiscardAction $discardGemAction,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $this->canonicalCallback($update->callbackData());
        $responder->answerCallback();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
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
        }
    }

    /**
     * @return array{0: string, 1: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}}
     */
    public function backpackPanel(Character $player, ?TypeEnum $type): array
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
            'style' => 'danger',
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

        return [$header . "\n\n" . $body, TelegramKeyboards::custom($buttons)];
    }

    /**
     * @return array{0: string, 1: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}}
     */
    public function bagPanel(Character $player): array
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
            'style' => 'danger',
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

        return [$text, TelegramKeyboards::custom($buttons)];
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
            'style' => 'danger',
        ]];

        if ($hasCandidate) {
            $text = __('menu.gear_pick_title', ['slot' => $slot->getLabel()]);
        } else {
            $text = __('menu.gear_pick_empty', ['slot' => $slot->getLabel()]);
        }

        $responder->edit($text, TelegramKeyboards::custom($buttons));
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
            'style' => 'danger',
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

        $responder->edit($text, TelegramKeyboards::custom($buttons));
    }

    private function backpackScreen(TelegramResponder $responder, Character $player, ?TypeEnum $type): void
    {
        [$text, $markup] = $this->backpackPanel($player, $type);
        $responder->edit($text, $markup);
    }

    private function bagScreen(TelegramResponder $responder, Character $player): void
    {
        [$text, $markup] = $this->bagPanel($player);
        $responder->edit($text, $markup);
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
                'style' => 'danger',
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

        $responder->edit($text, TelegramKeyboards::custom($buttons));
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
            TelegramKeyboards::bagDiscardConfirm($row->id),
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

        $responder->edit($text, TelegramKeyboards::bagGemCard($row->id));
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

        $responder->edit($text, TelegramKeyboards::bagPotionCard($row->id));
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

        $responder->edit($text, TelegramKeyboards::invDiscardConfirm($row->id));
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
