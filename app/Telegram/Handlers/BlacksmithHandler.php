<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackRepairAction;
use App\Actions\Backpack\BackpackRepairAllAction;
use App\Actions\City\CityBlacksmithBuyAction;
use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\TypeEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;
use App\Models\Character\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\LoadoutService;
use App\Services\Backpack\RepairService;
use App\Services\CityMenuService;
use App\Services\DailyContract\DailyContractService;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Shop\ShopCatalog;
use App\Support\Equipment\EquipmentDef;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use Throwable;

final class BlacksmithHandler
{
    public function __construct(
        private readonly BackpackRepairAction $repairItem,
        private readonly BackpackRepairAllAction $repairAll,
        private readonly CityBlacksmithBuyAction $buyItem,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly DailyContractService $dailyContracts,
        private readonly LoadoutService $loadout,
        private readonly QuestNpcPlacement $npcPlacement,
        private readonly RepairService $repairs,
        private readonly ShopCatalog $shopCatalog,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $gearState = PaginatedListKeyboard::listState($data, 'city:blacksmith:gear');
        $repairState = PaginatedListKeyboard::listState($data, 'city:blacksmith:repair');

        if (
            $gearState !== null
            || $repairState !== null
            || $data === 'city:blacksmith:gear'
            || $data === 'city:blacksmith:repair'
        ) {
            $player = $this->gate->requireCityPlayer($update, $responder);

            if ($player === false) {
                $responder->answerCallback();

                return;
            }

            if ($gearState !== null) {
                $this->blacksmithGear($responder, $player, $gearState['filter'], $gearState['page']);

                return;
            }

            if ($data === 'city:blacksmith:gear') {
                $this->blacksmithGear($responder, $player, 'WEAPON', 1);

                return;
            }

            if ($repairState !== null) {
                $this->blacksmithRepair($responder, $player, $repairState['filter'], $repairState['page']);

                return;
            }

            $this->blacksmithRepair($responder, $player, $this->firstDamagedRepairFilter($player), 1);

            return;
        }

        $responder->answerCallback();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($data === 'city:blacksmith') {
            $this->blacksmithScreen($responder, $player);

            return;
        }

        if (preg_match('/^city:blacksmith:gear:view:([A-Za-z0-9_]+):([A-Za-z0-9_]+)$/', $data, $m) === 1) {
            $this->blacksmithGearView($responder, $player, $m[1], $m[2]);

            return;
        }

        if (preg_match('/^city:blacksmith:buy:(.+)$/', $data, $m) === 1) {
            $this->blacksmithBuy($responder, $player, $m[1]);

            return;
        }

        if (preg_match('/^city:blacksmith:repair:view:(\d+):([A-Za-z0-9_]+)$/', $data, $m) === 1) {
            $this->blacksmithRepairView($responder, $player, (int) $m[1], $m[2]);

            return;
        }

        if (preg_match('/^city:blacksmith:repair:do:(\d+)$/', $data, $m) === 1) {
            $this->blacksmithRepairItem($responder, $player, (int) $m[1]);

            return;
        }

        if ($data === 'city:blacksmith:repair_all:yes') {
            $this->blacksmithRepairAll($responder, $player);

            return;
        }

        if ($data === 'city:blacksmith:repair_all') {
            $this->blacksmithRepairAllConfirm($responder, $player);
        }
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBlacksmithPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $blacksmithImage = config('bot.blacksmith_tavern_image');

        if (is_string($blacksmithImage) && $blacksmithImage !== '' && is_file($blacksmithImage)) {
            try {
                $responder->editPhoto($blacksmithImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function blacksmithBuy(TelegramResponder $responder, Character $player, string $catalogId): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $filter = $this->gearFilterFromCatalog($catalogId);
        $res = $this->buyItem->handle($player->tg_id, $catalogId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editBlacksmithPanel(
                $responder,
                $this->blacksmithErrorText($res->error),
                $this->blacksmithGearErrorMarkup($player, $res->error, $catalogId, $filter),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.bought', [
                'name' => TelegramHtml::escape($this->shopCatalog->findItem($catalogId)->itemName),
            ]),
            CityKeyboard::blacksmithGear(
                $this->blacksmithGearRows($res->character, $filter),
                $filter,
                1,
                count($this->blacksmithGearRows($res->character, 'all')),
            ),
        );
    }

    private function blacksmithGearView(
        TelegramResponder $responder,
        Character $player,
        string $catalogId,
        string $filter,
    ): void {
        $city = $this->cityMenu->currentCity($player);
        $filter = $this->gearFilter($filter);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if (
            ! $this->cityQuery->backpackInCityShop($city->id, $catalogId)
            || ! $this->shopCatalog->hasItem($catalogId)
        ) {
            $items = $this->blacksmithGearRows($player, $filter);
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.unavailable'),
                CityKeyboard::blacksmithGear(
                    $items,
                    $filter,
                    1,
                    count($this->blacksmithGearRows($player, 'all')),
                ),
            );

            return;
        }

        $def = $this->shopCatalog->findItem($catalogId);
        $text = $this->blacksmithGearCardText($def);
        $markup = CityKeyboard::blacksmithGearCard($catalogId, $filter);
        $imagePath = $this->blacksmithCatalogImagePath($catalogId);

        if (is_string($imagePath)) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $this->editBlacksmithPanel($responder, $text, $markup);
    }

    private function blacksmithGearCardText(EquipmentDef $def): string
    {
        return $this->loadout->catalogShopCardText($def)
            . "\n\n"
            . __('telegram.npc.blacksmith.gear_card_price', [
                'mark' => $this->currencyMark($def->currency),
                'price' => $def->price,
            ]);
    }

    private function blacksmithCatalogImagePath(string $catalogId): ?string
    {
        $model = BackpackCatalog::query()->find($catalogId);

        if (! $model instanceof BackpackCatalog) {
            return null;
        }

        return $model->localImagePath();
    }

    private function gearFilterFromCatalog(string $catalogId): string
    {
        if (! $this->shopCatalog->hasItem($catalogId)) {
            return 'WEAPON';
        }

        $type = $this->shopCatalog->findItem($catalogId)->itemType;

        return $this->gearFilter($type->value);
    }

    private function blacksmithErrorText(?string $error): string
    {
        if ($error === __('errors.not_enough_silver')) {
            return __('telegram.npc.blacksmith.error.not_enough_silver');
        }

        if ($error === __('errors.not_enough_gold')) {
            return __('telegram.npc.blacksmith.error.not_enough_gold');
        }

        if ($error === __('errors.inventory_full')) {
            return __('telegram.npc.blacksmith.error.inventory_full');
        }

        if (in_array($error, [__('errors.item_not_in_shop'), __('errors.pick_train_weapon'), __('errors.item_not_found')], true)) {
            return __('telegram.npc.blacksmith.error.unavailable');
        }

        if ($error === __('errors.cannot_repair')) {
            return __('telegram.npc.blacksmith.error.cannot_repair');
        }

        if ($error === __('errors.already_repaired')) {
            return __('telegram.npc.blacksmith.error.already_repaired');
        }

        if ($error === __('errors.needs_vip_smith') || $error === __('errors.vip_smith_only_vip')) {
            return __('telegram.npc.blacksmith.error.needs_vip_smith');
        }

        if ($error === __('errors.no_blacksmith')) {
            return __('telegram.npc.blacksmith.error.no_blacksmith');
        }

        return TelegramResponder::errorMessage($error);
    }

    private function blacksmithGear(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $responder->answerCallback();
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $filter = $this->gearFilter($filter);
        $items = $this->blacksmithGearRows($player, $filter);
        $totalCount = count($this->blacksmithGearRows($player, 'all'));
        $edge = PaginatedListKeyboard::isOutOfRange($page, count($items));
        $markup = CityKeyboard::blacksmithGear($items, $filter, $page, $totalCount);

        $responder->answerCallback();

        if ($edge && ! empty($items)) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('telegram.npc.blacksmith.gear_empty'),
                __('telegram.npc.blacksmith.gear'),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if (empty($items)) {
            $text = __('telegram.npc.blacksmith.gear_empty');
        } else {
            $text = __('telegram.npc.blacksmith.gear');
        }

        $this->editBlacksmithPanel($responder, $text, $markup);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function blacksmithGearErrorMarkup(
        Character $player,
        ?string $error,
        string $catalogId,
        string $filter,
    ): array {
        if ($error === __('errors.no_blacksmith') || $error === __('errors.city_unavailable')) {
            return CityKeyboard::backToTavern();
        }

        if (in_array($error, [
            __('errors.item_not_in_shop'),
            __('errors.pick_train_weapon'),
            __('errors.item_not_found'),
            __('errors.not_enough_silver'),
            __('errors.not_enough_gold'),
        ], true)) {
            $items = $this->blacksmithGearRows($player, $filter);

            return CityKeyboard::blacksmithGear(
                $items,
                $filter,
                1,
                count($this->blacksmithGearRows($player, 'all')),
            );
        }

        return CityKeyboard::blacksmithGearCard($catalogId, $filter);
    }

    /**
     * @return list<array{text: string, catalog_id: string}>
     */
    private function blacksmithGearRows(Character $player, string $filter): array
    {
        if ($player->city_id === null) {
            return [];
        }

        $type = CityKeyboard::gearTypeFromFilter($filter);
        $allowed = $this->cityQuery->backpackShopCatalogIds($player->city_id);
        $rows = [];

        if (! $type instanceof TypeEnum || $type === TypeEnum::WEAPON) {
            foreach ($this->shopCatalog->weaponsForMode('full') as $weapon) {
                if (! in_array($weapon->itemId, $allowed, true)) {
                    continue;
                }

                $rows[] = [
                    'text' => __('telegram.npc.blacksmith.gear_row', [
                        'name' => $weapon->itemName,
                    ]),
                    'catalog_id' => $weapon->itemId,
                ];
            }
        }

        if (! $type instanceof TypeEnum || $type === TypeEnum::ARMOR) {
            foreach ($this->shopCatalog->shopGear() as $item) {
                if (! in_array($item->itemId, $allowed, true)) {
                    continue;
                }

                $rows[] = [
                    'text' => __('telegram.npc.blacksmith.gear_row', [
                        'name' => $item->itemName,
                    ]),
                    'catalog_id' => $item->itemId,
                ];
            }
        }

        if (! $type instanceof TypeEnum || $type === TypeEnum::JEWELRY) {
            foreach ($this->shopCatalog->shopJewelry() as $jewelry) {
                if (! in_array($jewelry->itemId, $allowed, true)) {
                    continue;
                }

                $rows[] = [
                    'text' => __('telegram.npc.blacksmith.gear_row', [
                        'name' => $jewelry->itemName,
                    ]),
                    'catalog_id' => $jewelry->itemId,
                ];
            }
        }

        return $rows;
    }

    private function blacksmithRepair(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $responder->answerCallback();
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $filter = $this->gearFilter($filter);
        $items = $this->blacksmithRepairRows($player, $filter);
        $totalCount = count($this->blacksmithRepairRows($player, 'all'));
        $edge = PaginatedListKeyboard::isOutOfRange($page, count($items));

        if (empty($items)) {
            $repairAllBtn = null;
        } else {
            $repairAllBtn = __('telegram.npc.blacksmith.repair_all_btn');
        }

        $markup = CityKeyboard::blacksmithRepair($items, $filter, $page, $repairAllBtn, $totalCount);

        $responder->answerCallback();

        if ($edge && ! empty($items)) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('telegram.npc.blacksmith.repair_empty'),
                __('telegram.npc.blacksmith.repair'),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if (empty($items)) {
            $text = __('telegram.npc.blacksmith.repair_empty');
        } else {
            $text = __('telegram.npc.blacksmith.repair');
        }

        $this->editBlacksmithPanel($responder, $text, $markup);
    }

    private function blacksmithRepairAll(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $res = $this->repairAll->handle($player);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editBlacksmithPanel(
                $responder,
                $this->blacksmithErrorText($res->error),
                $this->blacksmithRepairListMarkup($player),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.repaired_all'),
            CityKeyboard::backToBlacksmith(),
        );
    }

    private function blacksmithRepairAllConfirm(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $damaged = $this->repairs->damagedList($player->tg_id);

        if ($damaged->isEmpty()) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.repair_empty'),
                CityKeyboard::backToBlacksmith(),
            );

            return;
        }

        $itemLines = [];

        foreach ($damaged as $row) {
            $itemLines[] = __('telegram.npc.blacksmith.repair_all_confirm_item', [
                'name' => TelegramHtml::escape($row->item_name),
                'current' => $row->durability,
                'max' => $row->max_durability,
            ]);
        }

        $filter = $this->firstDamagedRepairFilter($player);

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.repair_all_confirm', [
                'items' => implode("\n", $itemLines),
                'price' => $this->repairs->repairAllGoldCost($player),
            ]),
            CityKeyboard::blacksmithRepairAllConfirm($filter),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function blacksmithRepairListMarkup(Character $player): array
    {
        $filter = $this->firstDamagedRepairFilter($player);
        $items = $this->blacksmithRepairRows($player, $filter);

        if (empty($items)) {
            return CityKeyboard::backToBlacksmith();
        }

        return CityKeyboard::blacksmithRepair(
            $items,
            $filter,
            1,
            __('telegram.npc.blacksmith.repair_all_btn'),
            count($this->blacksmithRepairRows($player, 'all')),
        );
    }

    private function blacksmithRepairItem(TelegramResponder $responder, Character $player, int $rowId): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $res = $this->repairItem->handle($player, $rowId);

        if (! $res->ok || ! $res->character instanceof Character || ! $res->item instanceof BackpackItem) {
            $this->editBlacksmithPanel(
                $responder,
                $this->blacksmithErrorText($res->error),
                $this->blacksmithRepairListMarkup($player),
            );

            return;
        }

        $filter = $this->firstDamagedRepairFilter($res->character);
        $items = $this->blacksmithRepairRows($res->character, $filter);

        if (empty($items)) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.repaired'),
                CityKeyboard::backToBlacksmith(),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.repaired'),
            CityKeyboard::blacksmithRepair(
                $items,
                $filter,
                1,
                __('telegram.npc.blacksmith.repair_all_btn'),
                count($this->blacksmithRepairRows($res->character, 'all')),
            ),
        );
    }

    private function blacksmithRepairView(
        TelegramResponder $responder,
        Character $player,
        int $rowId,
        string $filter,
    ): void {
        $city = $this->cityMenu->currentCity($player);
        $filter = $this->gearFilter($filter);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $row = BackpackItem::query()
            ->where('tg_id', $player->tg_id)
            ->whereKey($rowId)
            ->first();

        if (
            ! $row instanceof BackpackItem
            || $row->durability === null
            || $row->max_durability === null
            || $row->durability >= $row->max_durability
            || ! $this->shopCatalog->hasItem($row->catalog_id)
        ) {
            $this->editBlacksmithPanel(
                $responder,
                $this->blacksmithErrorText(__('errors.already_repaired')),
                $this->blacksmithRepairListMarkup($player),
            );

            return;
        }

        $def = $this->shopCatalog->findItem($row->catalog_id);
        $text = $this->loadout->gearShopCardText($row, $def, true)
            . "\n\n"
            . __('telegram.npc.blacksmith.repair_card_price', [
                'mark' => $this->currencyMark(CurrencyEnum::SILVER),
                'price' => $this->repairs->repairCost($row),
            ]);
        $markup = CityKeyboard::blacksmithRepairCard($rowId, $filter);
        $imagePath = $this->blacksmithCatalogImagePath($row->catalog_id);

        if (is_string($imagePath)) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $this->editBlacksmithPanel($responder, $text, $markup);
    }

    /**
     * @return list<array{text: string, row_id: int}>
     */
    private function blacksmithRepairRows(Character $player, string $filter): array
    {
        $type = CityKeyboard::gearTypeFromFilter($filter);
        $rows = [];

        foreach ($this->repairs->damagedList($player->tg_id) as $row) {
            if ($type instanceof TypeEnum && $row->item_type !== $type) {
                continue;
            }

            $rows[] = [
                'text' => __('telegram.npc.blacksmith.repair_row', [
                    'name' => $row->item_name,
                ]),
                'row_id' => $row->id,
            ];
        }

        return $rows;
    }

    private function firstDamagedRepairFilter(Character $player): string
    {
        foreach (CityKeyboard::gearTypeFilters() as $row) {
            if (! empty($this->blacksmithRepairRows($player, $row['id']))) {
                return $row['id'];
            }
        }

        return 'WEAPON';
    }

    private function gearFilter(string $filter): string
    {
        if ($filter === 'wpn') {
            return 'WEAPON';
        }

        if ($filter === 'arm') {
            return 'ARMOR';
        }

        if ($filter === 'jwl') {
            return 'JEWELRY';
        }

        if (in_array($filter, ['WEAPON', 'ARMOR', 'JEWELRY'], true)) {
            return $filter;
        }

        return 'WEAPON';
    }

    private function blacksmithScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Blacksmith);

        if (! $city instanceof City || ! $city->has_blacksmith) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.error.no_blacksmith'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        $hasDaily = $this->dailyContracts
            ->turnInableForIssuer($player, $city, NpcEnum::Blacksmith)
            ->isNotEmpty();

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.offer'),
            CityKeyboard::blacksmithWithDaily($hasDaily, $back),
        );
    }

    private function currencyMark(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return '🥇';
        }

        return '🪙';
    }
}
