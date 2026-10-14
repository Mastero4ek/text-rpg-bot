<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackDiscardAction;
use App\Actions\Bag\BagGemDiscardAction;
use App\Actions\Bag\BagGemSocketAction;
use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagCatalog as BagCatalogModel;
use App\Models\Bag\BagItem;
use App\Models\Character\Character;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\Character\CharacterService;
use App\Services\Registration\RegistrationService;
use App\Services\Shop\ShopCatalog;
use App\Support\Gem\GemDef;
use App\Support\Gem\GemMfText;
use App\Support\Telegram\EquipPreviewText;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
use Throwable;

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
        private readonly BagGemSocketAction $socketGem,
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

        if (preg_match('/^gear:view:(\d+)$/', $data, $m) === 1) {
            $this->gearViewScreen($responder, $player, (int) $m[1]);

            return;
        }

        $gemPickTail = '(?::pick:(' . $slotPattern . '))?';

        if (preg_match('/^gear:gem:view:(\d+):(\d+)' . $gemPickTail . '$/', $data, $m) === 1) {
            $this->gearGemViewScreen(
                $responder,
                $player,
                (int) $m[1],
                (int) $m[2],
                $this->optionalPickSlotFromMatch($m, 3),
            );

            return;
        }

        if (preg_match('/^gear:gem:pick:(\d+)' . $gemPickTail . ':list:([A-Za-z0-9_]+):(-?\d+)$/', $data, $m) === 1) {
            $this->gearGemPickScreen(
                $responder,
                $player,
                (int) $m[1],
                (int) $m[4],
                $this->optionalPickSlotFromMatch($m, 2),
            );

            return;
        }

        if (preg_match('/^gear:gem:pick:(\d+)' . $gemPickTail . '$/', $data, $m) === 1) {
            $this->gearGemPickScreen(
                $responder,
                $player,
                (int) $m[1],
                1,
                $this->optionalPickSlotFromMatch($m, 2),
            );

            return;
        }

        if (preg_match('/^gear:gem:ask:(\d+):(\d+)' . $gemPickTail . '$/', $data, $m) === 1) {
            $this->gearGemAskScreen(
                $responder,
                $player,
                (int) $m[1],
                (int) $m[2],
                $this->optionalPickSlotFromMatch($m, 3),
            );

            return;
        }

        if (preg_match('/^gear:gem:socket:(\d+):(\d+)' . $gemPickTail . '$/', $data, $m) === 1) {
            $this->gearGemSocket(
                $responder,
                $player,
                (int) $m[1],
                (int) $m[2],
                $this->optionalPickSlotFromMatch($m, 3),
            );

            return;
        }

        if (preg_match('/^gear:pick:(' . $slotPattern . '):list:([A-Za-z0-9_]+):(-?\d+)$/', $data, $m) === 1) {
            $this->gearPickScreen($responder, $player, SlotEnum::from($m[1]), (int) $m[3]);

            return;
        }

        if (preg_match('/^gear:pick:(' . $slotPattern . ')$/', $data, $m) === 1) {
            $this->gearPickScreen($responder, $player, SlotEnum::from($m[1]), 1);

            return;
        }

        $invList = PaginatedListKeyboard::listState($data, 'inv');

        if ($invList !== null) {
            $this->backpackScreen($responder, $player, $invList['filter'], $invList['page']);

            return;
        }

        if ($data === 'menu:inv' || $data === 'inv:filter:all') {
            $this->backpackScreen($responder, $player, 'WEAPON', 1);

            return;
        }

        $bagList = PaginatedListKeyboard::listState($data, 'bag');

        if ($bagList !== null) {
            $this->bagScreen($responder, $player, $bagList['filter'], $bagList['page']);

            return;
        }

        if ($data === 'menu:bag' || $data === 'bag:filter:potions') {
            $this->bagScreen($responder, $player, 'POTION', 1);

            return;
        }

        if ($data === 'bag:filter:gems') {
            $this->bagScreen($responder, $player, 'GEM', 1);

            return;
        }

        if (preg_match('/^bag:filter:(POTION|GEM|QUEST)$/', $data, $m) === 1) {
            $this->bagScreen($responder, $player, $m[1], 1);

            return;
        }

        if (preg_match('/^inv:filter:(WEAPON|ARMOR|JEWELRY)$/', $data, $m) === 1) {
            $this->backpackScreen($responder, $player, $m[1], 1);

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

        if (preg_match('/^inv:eq:ask:(\d+)(?::(' . $slotPattern . '))?$/', $data, $m) === 1) {
            if (isset($m[2])) {
                $this->equipConfirmScreen($responder, $player, (int) $m[1], SlotEnum::from($m[2]));
            } else {
                $this->equipConfirmScreen($responder, $player, (int) $m[1], null);
            }

            return;
        }

        if (preg_match('/^inv:eq:yes:(\d+)(?::(' . $slotPattern . '))?$/', $data, $m) === 1) {
            if (isset($m[2])) {
                $this->equipToSlot($responder, $player, (int) $m[1], SlotEnum::from($m[2]));
            } else {
                $this->equip($responder, $player, (int) $m[1]);
            }

            return;
        }

        if (preg_match('/^inv:uneq:ask:(\d+)$/', $data, $m) === 1) {
            $this->unequipConfirmScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^inv:uneq:yes:(\d+)$/', $data, $m) === 1) {
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

        if (preg_match('/^bag:card:(\d+)$/', $data, $m) === 1) {
            $this->bagCardScreen($responder, $player, (int) $m[1]);

            return;
        }

        if (preg_match('/^bag:(gem|potion|quest):(\d+)$/', $data, $m) === 1) {
            $this->bagCardScreen($responder, $player, (int) $m[2]);

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
    public function backpackPanel(Character $player, string $filter, int $page): array
    {
        $filter = $this->backpackFilter($filter);
        $current = $this->backpack->rowCount($player->tg_id);
        $max = $this->backpack->maxRows($player);

        $type = TypeEnum::from($filter);
        $items = [];

        foreach ($this->backpack->listByType($player->tg_id, $type) as $row) {
            $items[] = [
                'text' => $this->backpack->rowLabel($row),
                'callback_data' => 'inv:card:' . $row->id,
            ];
        }

        $header = __('profile.backpack_header_all', [
            'current' => $current,
            'max' => $max,
        ]);

        if ($current === 0) {
            $text = $header . "\n\n" . __('profile.inventory_empty');
        } elseif (empty($items)) {
            $text = $header . "\n\n" . __('profile.inventory_empty_type');
        } else {
            $text = $header;
        }

        return [$text, TelegramKeyboards::backpackList($items, $filter, $page, $current)];
    }

    /**
     * @return array{0: string, 1: array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}}
     */
    public function bagPanel(Character $player, string $filter, int $page): array
    {
        $filter = $this->bagFilter($filter);
        $current = $this->bag->bagRowCount($player);
        $max = $this->bag->bagMaxRows($player);
        $kind = BagKindEnum::from($filter);
        $items = [];

        if ($kind === BagKindEnum::POTION) {
            foreach ($this->bag->loosePotions($player) as $row) {
                if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                    continue;
                }

                $def = $this->bagCatalog->findPotion($row->catalog_id);
                $items[] = [
                    'text' => __('profile.bag_potion_row', [
                        'name' => $def->name,
                        'quantity' => $row->quantity,
                    ]),
                    'callback_data' => 'bag:card:' . $row->id,
                ];
            }
        } elseif ($kind === BagKindEnum::GEM) {
            foreach ($this->bag->looseGems($player) as $row) {
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

                $items[] = [
                    'text' => $label,
                    'callback_data' => 'bag:card:' . $row->id,
                ];
            }
        } else {
            foreach ($this->bag->looseQuestItems($player) as $row) {
                if (! $this->bagCatalog->hasQuestItem($row->catalog_id)) {
                    continue;
                }

                $def = $this->bagCatalog->findQuestItem($row->catalog_id);

                if ($row->quantity > 1) {
                    $label = __('profile.bag_quest_row_qty', [
                        'name' => $def->name,
                        'quantity' => $row->quantity,
                    ]);
                } else {
                    $label = __('profile.bag_quest_row', [
                        'name' => $def->name,
                    ]);
                }

                $items[] = [
                    'text' => $label,
                    'callback_data' => 'bag:card:' . $row->id,
                ];
            }
        }

        $header = __('profile.bag_header', [
            'current' => $current,
            'max' => $max,
        ]);

        if ($current === 0) {
            $text = $header . "\n\n" . __('profile.bag_empty');
        } elseif (empty($items)) {
            $text = $header . "\n\n" . __('profile.bag_empty_type');
        } else {
            $text = $header;
        }

        return [$text, TelegramKeyboards::bagList($items, $filter, $page, $current)];
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

    private function equipConfirmScreen(
        TelegramResponder $responder,
        Character $player,
        int $rowId,
        ?SlotEnum $slot,
    ): void {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || ! $this->shop->hasItem($row->catalog_id)) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $def = $this->shop->findItem($row->catalog_id);

        if ($slot instanceof SlotEnum) {
            $targetSlot = $slot;
            $cancel = 'inv:card:' . $row->id . ':pick:' . $slot->value;
        } elseif ($def->slot instanceof SlotEnum) {
            $targetSlot = $def->slot;
            $cancel = 'inv:card:' . $row->id;
        } else {
            $responder->reply(__('errors.cannot_equip'), null);

            return;
        }

        $changes = EquipPreviewText::format(
            $this->loadout->equipStatChanges($player, $row, $targetSlot),
        );
        $text = __('menu.equip_confirm', [
            'name' => TelegramHtml::escape($row->item_name),
            'changes' => $changes,
        ]);

        $this->editGearItemPanel(
            $responder,
            $player,
            $row->catalog_id,
            $text,
            TelegramKeyboards::gearEquipConfirm($row->id, $slot, $cancel),
        );
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

    private function unequipConfirmScreen(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || ! $row->isEquipped()) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $changes = EquipPreviewText::format(
            $this->loadout->unequipStatChanges($player, $row),
        );
        $text = __('menu.unequip_confirm', [
            'name' => TelegramHtml::escape($row->item_name),
            'changes' => $changes,
        ]);

        $this->editGearItemPanel(
            $responder,
            $player,
            $row->catalog_id,
            $text,
            TelegramKeyboards::gearUnequipConfirm($row->id, 'gear:view:' . $row->id),
        );
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editGearPanel(TelegramResponder $responder, Character $player, string $text, array $markup): void
    {
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

    private function gearPickScreen(
        TelegramResponder $responder,
        Character $player,
        SlotEnum $slot,
        int $page,
    ): void {
        $items = [];

        foreach ($this->backpack->list($player->tg_id) as $row) {
            if (! $this->shop->hasItem($row->catalog_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->catalog_id);

            if (! $this->loadout->fitsEquipSlot($player, $def, $slot)) {
                continue;
            }

            $items[] = [
                'text' => $row->item_name,
                'callback_data' => 'inv:card:' . $row->id . ':pick:' . $slot->value,
            ];
        }

        $totalCount = count($items);
        $edge = PaginatedListKeyboard::isOutOfRange($page, $totalCount);
        $markup = TelegramKeyboards::gearPickList($items, $slot, $page, $totalCount);

        if ($edge && $totalCount > 0) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('menu.gear_pick_empty', ['slot' => $slot->getLabel()]),
                __('menu.gear_pick_title', ['slot' => $slot->getLabel()]),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if ($totalCount === 0) {
            $text = __('menu.gear_pick_empty', ['slot' => $slot->getLabel()]);
        } else {
            $text = __('menu.gear_pick_title', ['slot' => $slot->getLabel()]);
        }

        $responder->edit($text, $markup);
    }

    private function gearScreen(TelegramResponder $responder, Character $player): void
    {
        $loadout = $this->loadout->forCharacter($player);

        $this->editGearPanel(
            $responder,
            $player,
            $this->characters->gearPanelText($player),
            TelegramKeyboards::gearDoll($loadout),
        );
    }

    private function gearGemPickScreen(
        TelegramResponder $responder,
        Character $player,
        int $hostId,
        int $page,
        ?SlotEnum $pickSlot,
    ): void {
        $host = BackpackItem::query()
            ->where('id', $hostId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($host === null || $this->bag->freeSocketCount($host) <= 0) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $pickTail = $this->gearGemPickTail($pickSlot);
        $items = [];

        foreach ($this->bag->looseGems($player) as $gem) {
            if (! $this->bagCatalog->hasGem($gem->catalog_id)) {
                continue;
            }

            $def = $this->bagCatalog->findGem($gem->catalog_id);
            $items[] = [
                'text' => $def->name,
                'callback_data' => 'gear:gem:ask:' . $hostId . ':' . $gem->id . $pickTail,
            ];
        }

        $totalCount = count($items);
        $edge = PaginatedListKeyboard::isOutOfRange($page, $totalCount);
        $markup = TelegramKeyboards::gearGemPickList(
            $items,
            $hostId,
            $page,
            $totalCount,
            $this->gearItemBackCallback($host, $pickSlot),
            $pickSlot,
        );

        if ($edge && $totalCount > 0) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('menu.gear_gem_pick_empty'),
                __('menu.gear_gem_pick_title'),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if ($totalCount === 0) {
            $text = __('menu.gear_gem_pick_empty');
        } else {
            $text = __('menu.gear_gem_pick_title');
        }

        if ($responder->callbackHasPhoto()) {
            $this->showInventoryTextPanel($responder, $player, $text, $markup);

            return;
        }

        $responder->edit($text, $markup);
    }

    private function gearGemAskScreen(
        TelegramResponder $responder,
        Character $player,
        int $hostId,
        int $bagItemId,
        ?SlotEnum $pickSlot,
    ): void {
        $host = BackpackItem::query()
            ->where('id', $hostId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($host === null || $this->bag->freeSocketCount($host) <= 0) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $gem = $this->looseBagItem($player, $bagItemId);

        if (! $gem instanceof BagItem || $gem->kind !== BagKindEnum::GEM || ! $this->bagCatalog->hasGem($gem->catalog_id)) {
            $responder->reply(__('errors.gem_not_found'), null);

            return;
        }

        $def = $this->bagCatalog->findGem($gem->catalog_id);
        $text = $this->gemCardText($def, (int) $gem->durability)
            . "\n\n"
            . __('profile.gear_gem_socket_warn');

        $this->editBagItemPanel(
            $responder,
            $gem->catalog_id,
            $text,
            TelegramKeyboards::gearGemSocketConfirm($hostId, $bagItemId, $pickSlot),
        );
    }

    private function gearGemSocket(
        TelegramResponder $responder,
        Character $player,
        int $hostId,
        int $bagItemId,
        ?SlotEnum $pickSlot,
    ): void {
        $res = $this->socketGem->handle($player, $hostId, $bagItemId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $responder->reply(TelegramResponder::errorMessage($res->error), null);

            return;
        }

        $host = $res->item;

        if ($host instanceof BackpackItem && $host->isEquipped()) {
            $this->gearViewScreen($responder, $res->character, $hostId);

            return;
        }

        $this->itemCardScreen($responder, $res->character, $hostId, $pickSlot);
    }

    private function gearGemViewScreen(
        TelegramResponder $responder,
        Character $player,
        int $hostId,
        int $bagItemId,
        ?SlotEnum $pickSlot,
    ): void {
        $host = BackpackItem::query()
            ->where('id', $hostId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($host === null) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $gem = BagItem::query()
            ->where('id', $bagItemId)
            ->where('tg_id', $player->tg_id)
            ->where('backpack_item_id', $hostId)
            ->where('kind', BagKindEnum::GEM->value)
            ->first();

        if (! $gem instanceof BagItem || ! $this->bagCatalog->hasGem($gem->catalog_id)) {
            $responder->reply(__('errors.gem_not_found'), null);

            return;
        }

        $def = $this->bagCatalog->findGem($gem->catalog_id);
        $text = $this->gemCardText($def, (int) $gem->durability);
        $markup = TelegramKeyboards::gearGemViewCard($this->gearItemBackCallback($host, $pickSlot));

        $this->editBagItemPanel($responder, $gem->catalog_id, $text, $markup);
    }

    private function gearViewScreen(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || ! $row->isEquipped()) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if (! $this->shop->hasItem($row->catalog_id)) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $def = $this->shop->findItem($row->catalog_id);
        $text = $this->loadout->gearShopCardText($row, $def, false);
        $markup = TelegramKeyboards::gearEquippedCard($row->id, $this->gearGemButtons($row, null));

        $this->editGearItemPanel($responder, $player, $row->catalog_id, $text, $markup);
    }

    private function bagFilter(string $filter): string
    {
        if (in_array($filter, ['POTION', 'GEM', 'QUEST'], true)) {
            return $filter;
        }

        return 'POTION';
    }

    private function bagScreen(
        TelegramResponder $responder,
        Character $player,
        string $filter,
        int $page,
    ): void {
        $filter = $this->bagFilter($filter);
        $kind = BagKindEnum::from($filter);

        if ($kind === BagKindEnum::POTION) {
            $filteredCount = $this->bag->loosePotions($player)->count();
        } elseif ($kind === BagKindEnum::GEM) {
            $filteredCount = $this->bag->looseGems($player)->count();
        } else {
            $filteredCount = $this->bag->looseQuestItems($player)->count();
        }

        $edge = PaginatedListKeyboard::isOutOfRange($page, $filteredCount);
        [$text, $markup] = $this->bagPanel($player, $filter, $page);

        if ($responder->callbackHasPhoto()) {
            $this->showInventoryTextPanel($responder, $player, $text, $markup);

            return;
        }

        if ($edge && $filteredCount > 0) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('profile.bag_empty_type'),
                $text,
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $responder->edit($text, $markup);
    }

    private function backpackFilter(string $filter): string
    {
        if (in_array($filter, ['WEAPON', 'ARMOR', 'JEWELRY'], true)) {
            return $filter;
        }

        return 'WEAPON';
    }

    private function backpackScreen(
        TelegramResponder $responder,
        Character $player,
        string $filter,
        int $page,
    ): void {
        $filter = $this->backpackFilter($filter);
        $type = TypeEnum::from($filter);
        $filteredCount = $this->backpack->listByType($player->tg_id, $type)->count();
        $edge = PaginatedListKeyboard::isOutOfRange($page, $filteredCount);
        [$text, $markup] = $this->backpackPanel($player, $filter, $page);

        if ($responder->callbackHasPhoto()) {
            $this->showInventoryTextPanel($responder, $player, $text, $markup);

            return;
        }

        if ($edge && $filteredCount > 0) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('profile.inventory_empty_type'),
                $text,
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
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

        if ($pickSlot instanceof SlotEnum) {
            $text = $this->loadout->gearShopCardText($row, $def, true);
            $markup = TelegramKeyboards::gearPickCard(
                $row->id,
                $pickSlot,
                $this->gearGemButtons($row, $pickSlot),
            );
            $this->editGearItemPanel($responder, $player, $row->catalog_id, $text, $markup);

            return;
        }

        if ($row->isEquipped()) {
            $this->gearViewScreen($responder, $player, $row->id);

            return;
        }

        $text = $this->loadout->gearShopCardText($row, $def, true);

        $this->editGearItemPanel(
            $responder,
            $player,
            $row->catalog_id,
            $text,
            TelegramKeyboards::invCard($row->id),
        );
    }

    private function bagCardScreen(TelegramResponder $responder, Character $player, int $bagItemId): void
    {
        $row = $this->looseBagItem($player, $bagItemId);

        if (! $row instanceof BagItem) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        if ($row->kind === BagKindEnum::GEM) {
            if (! $this->bagCatalog->hasGem($row->catalog_id)) {
                $responder->reply(__('errors.gem_not_found'), null);

                return;
            }

            $def = $this->bagCatalog->findGem($row->catalog_id);
            $this->editBagItemPanel(
                $responder,
                $row->catalog_id,
                $this->gemCardText($def, (int) $row->durability),
                TelegramKeyboards::bagCard($row->id, $row->kind->value),
            );

            return;
        }

        if ($row->kind === BagKindEnum::POTION) {
            if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                $responder->reply(__('errors.item_not_found'), null);

                return;
            }

            $def = $this->bagCatalog->findPotion($row->catalog_id);

            if ($def->profile === ProfileEnum::STAMINA) {
                $effectMark = '💚';
            } else {
                $effectMark = '❤️';
            }

            if (is_string($def->description) && $def->description !== '') {
                $text = __('profile.bag_potion_card', [
                    'name' => TelegramHtml::escape($def->name),
                    'description' => TelegramHtml::escape($def->description),
                    'effect_mark' => $effectMark,
                    'effect' => $def->effectValue,
                ]);
            } else {
                $text = __('profile.bag_potion_card_no_desc', [
                    'name' => TelegramHtml::escape($def->name),
                    'effect_mark' => $effectMark,
                    'effect' => $def->effectValue,
                ]);
            }

            $this->editBagItemPanel(
                $responder,
                $row->catalog_id,
                $text,
                TelegramKeyboards::bagCard($row->id, $row->kind->value),
            );

            return;
        }

        if (! $this->bagCatalog->hasQuestItem($row->catalog_id)) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $def = $this->bagCatalog->findQuestItem($row->catalog_id);

        if (is_string($def->description) && $def->description !== '') {
            $description = TelegramHtml::escape($def->description);
        } else {
            $description = '—';
        }

        $text = __('profile.bag_quest_card', [
            'name' => TelegramHtml::escape($def->name),
            'description' => $description,
            'quantity' => $row->quantity,
        ]);
        $this->editBagItemPanel(
            $responder,
            $row->catalog_id,
            $text,
            TelegramKeyboards::bagCard($row->id, $row->kind->value),
        );
    }

    private function bagDiscardConfirmScreen(TelegramResponder $responder, Character $player, int $bagItemId): void
    {
        $row = $this->looseBagItem($player, $bagItemId);

        if (! $row instanceof BagItem) {
            $responder->reply(__('errors.item_not_found'), null);

            return;
        }

        $name = TelegramHtml::escape($this->bagItemName($row));

        $responder->edit(
            __('profile.discard_gem_confirm', ['name' => $name]),
            TelegramKeyboards::bagDiscardConfirm($row->id),
        );
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

        $notice = __('profile.discarded', [
            'name' => TelegramHtml::escape($name),
        ]);
        [$text, $markup] = $this->bagPanel($res->character, 'POTION', 1);
        $parts = explode("\n\n", $text, 2);

        if (count($parts) === 1) {
            $text = $parts[0] . "\n\n" . $notice;
        } else {
            $text = $parts[0] . "\n\n" . $notice . "\n\n" . $parts[1];
        }

        $this->showInventoryTextPanel($responder, $res->character, $text, $markup);
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

        $notice = __('profile.discarded', [
            'name' => TelegramHtml::escape($name),
        ]);
        [$text, $markup] = $this->backpackPanel($res->character, 'WEAPON', 1);
        $parts = explode("\n\n", $text, 2);

        if (count($parts) === 1) {
            $text = $parts[0] . "\n\n" . $notice;
        } else {
            $text = $parts[0] . "\n\n" . $notice . "\n\n" . $parts[1];
        }

        $this->showInventoryTextPanel($responder, $res->character, $text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function showInventoryTextPanel(
        TelegramResponder $responder,
        Character $player,
        string $text,
        array $markup,
    ): void {
        if ($responder->callbackHasPhoto()) {
            $responder->deleteUpdateMessage();
            $messageId = $responder->reply($text, $markup);
            app(RegistrationService::class)->rememberTelegramMessage(
                $player,
                $responder->chatId(),
                $messageId,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());
        $responder->edit($text, $markup);
    }

    private function bagItemName(BagItem $row): string
    {
        return match ($row->kind) {
            BagKindEnum::GEM => $this->bagCatalog->hasGem($row->catalog_id)
                ? $this->bagCatalog->findGem($row->catalog_id)->name
                : $row->catalog_id,
            BagKindEnum::POTION => $this->bagCatalog->hasPotion($row->catalog_id)
                ? $this->bagCatalog->findPotion($row->catalog_id)->name
                : $row->catalog_id,
            BagKindEnum::QUEST => $this->bagCatalog->hasQuestItem($row->catalog_id)
                ? $this->bagCatalog->findQuestItem($row->catalog_id)->name
                : $row->catalog_id,
        };
    }

    private function looseBagItem(Character $player, int $bagItemId): ?BagItem
    {
        return BagItem::query()
            ->where('id', $bagItemId)
            ->where('tg_id', $player->tg_id)
            ->whereNull('backpack_item_id')
            ->first();
    }

    private function bagCatalogImagePath(string $catalogId): ?string
    {
        $model = BagCatalogModel::query()->find($catalogId);

        if (! $model instanceof BagCatalogModel) {
            return null;
        }

        return $model->localImagePath();
    }

    private function gemCardText(GemDef $def, int $current): string
    {
        $description = $def->description;

        if ($description === null || $description === '') {
            return __('profile.bag_gem_card_no_desc', [
                'name' => TelegramHtml::escape($def->name),
                'type' => TelegramHtml::escape($def->type->getLabel()),
                'mf' => GemMfText::forDef($def),
                'current' => $current,
                'max' => $def->maxDurability,
            ]);
        }

        return __('profile.bag_gem_card', [
            'name' => TelegramHtml::escape($def->name),
            'description' => TelegramHtml::escape($description),
            'type' => TelegramHtml::escape($def->type->getLabel()),
            'mf' => GemMfText::forDef($def),
            'current' => $current,
            'max' => $def->maxDurability,
        ]);
    }

    private function backpackCatalogImagePath(string $catalogId): ?string
    {
        $model = BackpackCatalog::query()->find($catalogId);

        if (! $model instanceof BackpackCatalog) {
            return null;
        }

        return $model->localImagePath();
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBagItemPanel(
        TelegramResponder $responder,
        string $catalogId,
        string $text,
        array $markup,
    ): void {
        $imagePath = $this->bagCatalogImagePath($catalogId);

        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editGearItemPanel(
        TelegramResponder $responder,
        Character $player,
        string $catalogId,
        string $text,
        array $markup,
    ): void {
        $imagePath = $this->backpackCatalogImagePath($catalogId);

        if ($imagePath !== null) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

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

    /**
     * @return list<array{text: string, callback_data: string}>
     */
    private function gearGemButtons(BackpackItem $row, ?SlotEnum $pickSlot): array
    {
        $slotCount = $this->bag->gemSlotCount($row);

        if ($slotCount <= 0) {
            return [];
        }

        $pickTail = $this->gearGemPickTail($pickSlot);
        $instances = $this->bag->socketedInstances($row)->values();
        $buttons = [];

        for ($index = 0; $index < $slotCount; $index++) {
            $gem = $instances->get($index);

            if (! $gem instanceof BagItem) {
                $buttons[] = [
                    'text' => __('menu.gear_gem_empty'),
                    'callback_data' => 'gear:gem:pick:' . $row->id . $pickTail,
                ];

                continue;
            }

            if ($this->bagCatalog->hasGem($gem->catalog_id)) {
                $name = $this->bagCatalog->findGem($gem->catalog_id)->name;
            } else {
                $name = $gem->catalog_id;
            }

            $buttons[] = [
                'text' => $name,
                'callback_data' => 'gear:gem:view:' . $row->id . ':' . $gem->id . $pickTail,
            ];
        }

        return $buttons;
    }

    private function gearGemPickTail(?SlotEnum $pickSlot): string
    {
        if (! $pickSlot instanceof SlotEnum) {
            return '';
        }

        return ':pick:' . $pickSlot->value;
    }

    private function gearItemBackCallback(BackpackItem $row, ?SlotEnum $pickSlot): string
    {
        if ($row->isEquipped()) {
            return 'gear:view:' . $row->id;
        }

        if ($pickSlot instanceof SlotEnum) {
            return 'inv:card:' . $row->id . ':pick:' . $pickSlot->value;
        }

        return 'inv:card:' . $row->id;
    }

    /**
     * @param  array<int, string>  $match
     */
    private function optionalPickSlotFromMatch(array $match, int $index): ?SlotEnum
    {
        if (! array_key_exists($index, $match)) {
            return null;
        }

        $raw = $match[$index];

        if ($raw === '') {
            return null;
        }

        return SlotEnum::from($raw);
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
