<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Backpack\BackpackRepairAction;
use App\Actions\Backpack\BackpackRepairAllAction;
use App\Actions\City\CityBlacksmithBuyAction;
use App\Enums\Economy\CurrencyEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\RepairService;
use App\Services\CityMenuService;
use App\Services\Shop\ShopCatalog;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class BlacksmithHandler
{
    public function __construct(
        private readonly BackpackRepairAction $repairItem,
        private readonly BackpackRepairAllAction $repairAll,
        private readonly CityBlacksmithBuyAction $buyItem,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly RepairService $repairs,
        private readonly ShopCatalog $shopCatalog,
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

        if ($data === 'city:blacksmith') {
            $this->blacksmithScreen($responder, $player);

            return;
        }

        if ($data === 'city:blacksmith:gear') {
            $this->blacksmithGear($responder, $player);

            return;
        }

        if (preg_match('/^city:blacksmith:buy:(.+)$/', $data, $m) === 1) {
            $this->blacksmithBuy($responder, $player, $m[1]);

            return;
        }

        if ($data === 'city:blacksmith:repair_all') {
            $this->blacksmithRepairAll($responder, $player);

            return;
        }

        if (preg_match('/^city:blacksmith:repair:(\d+)$/', $data, $m) === 1) {
            $this->blacksmithRepairItem($responder, $player, (int) $m[1]);

            return;
        }

        if ($data === 'city:blacksmith:repair') {
            $this->blacksmithRepair($responder, $player);
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

        $res = $this->buyItem->handle($player->tg_id, $catalogId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editBlacksmithPanel(
                $responder,
                $this->blacksmithErrorText($res->error),
                $this->blacksmithGearErrorMarkup($player, $res->error),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.bought', [
                'name' => TelegramHtml::escape($this->shopCatalog->findItem($catalogId)->itemName),
            ]),
            CityKeyboard::blacksmithGear($this->blacksmithGearRows($res->character)),
        );
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

    private function blacksmithGear(TelegramResponder $responder, Character $player): void
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

        $items = $this->blacksmithGearRows($player);

        if (empty($items)) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.gear_empty'),
                CityKeyboard::backToBlacksmith(),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.gear'),
            CityKeyboard::blacksmithGear($items),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function blacksmithGearErrorMarkup(Character $player, ?string $error): array
    {
        if ($error === __('errors.no_blacksmith') || $error === __('errors.city_unavailable')) {
            return CityKeyboard::backToTavern();
        }

        $items = $this->blacksmithGearRows($player);

        if (empty($items)) {
            return CityKeyboard::backToBlacksmith();
        }

        return CityKeyboard::blacksmithGear($items);
    }

    /**
     * @return list<array{text: string, catalog_id: string}>
     */
    private function blacksmithGearRows(Character $player): array
    {
        if ($player->city_id === null) {
            return [];
        }

        $allowed = $this->cityQuery->backpackShopCatalogIds($player->city_id);
        $rows = [];

        foreach ($this->shopCatalog->weaponsForMode('full') as $weapon) {
            if (! in_array($weapon->itemId, $allowed, true)) {
                continue;
            }

            $rows[] = [
                'text' => __('telegram.npc.blacksmith.gear_row', [
                    'name' => TelegramHtml::escape($weapon->itemName),
                    'price' => $weapon->price,
                    'mark' => $this->currencyMark($weapon->currency),
                ]),
                'catalog_id' => $weapon->itemId,
            ];
        }

        foreach ($this->shopCatalog->shopGear() as $item) {
            if (! in_array($item->itemId, $allowed, true)) {
                continue;
            }

            $rows[] = [
                'text' => __('telegram.npc.blacksmith.gear_row', [
                    'name' => TelegramHtml::escape($item->itemName),
                    'price' => $item->price,
                    'mark' => $this->currencyMark($item->currency),
                ]),
                'catalog_id' => $item->itemId,
            ];
        }

        foreach ($this->shopCatalog->shopJewelry() as $jewelry) {
            if (! in_array($jewelry->itemId, $allowed, true)) {
                continue;
            }

            $rows[] = [
                'text' => __('telegram.npc.blacksmith.gear_row', [
                    'name' => TelegramHtml::escape($jewelry->itemName),
                    'price' => $jewelry->price,
                    'mark' => $this->currencyMark($jewelry->currency),
                ]),
                'catalog_id' => $jewelry->itemId,
            ];
        }

        return $rows;
    }

    private function blacksmithRepair(TelegramResponder $responder, Character $player): void
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

        $items = $this->blacksmithRepairRows($player);

        if (empty($items)) {
            $this->editBlacksmithPanel(
                $responder,
                __('telegram.npc.blacksmith.repair_empty'),
                CityKeyboard::backToBlacksmith(),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.repair'),
            CityKeyboard::blacksmithRepair(
                $items,
                __('telegram.npc.blacksmith.repair_all_btn', [
                    'price' => $this->repairs->repairAllGoldCost($player),
                ]),
            ),
        );
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
                $this->blacksmithRepairErrorMarkup($player, $res->error),
            );

            return;
        }

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.repaired_all'),
            CityKeyboard::backToBlacksmith(),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function blacksmithRepairErrorMarkup(Character $player, ?string $error): array
    {
        if ($error === __('errors.no_blacksmith') || $error === __('errors.city_unavailable')) {
            return CityKeyboard::backToTavern();
        }

        $items = $this->blacksmithRepairRows($player);

        if (empty($items)) {
            return CityKeyboard::backToBlacksmith();
        }

        return CityKeyboard::blacksmithRepair(
            $items,
            __('telegram.npc.blacksmith.repair_all_btn', [
                'price' => $this->repairs->repairAllGoldCost($player),
            ]),
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
                $this->blacksmithRepairErrorMarkup($player, $res->error),
            );

            return;
        }

        $items = $this->blacksmithRepairRows($res->character);

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
                __('telegram.npc.blacksmith.repair_all_btn', [
                    'price' => $this->repairs->repairAllGoldCost($res->character),
                ]),
            ),
        );
    }

    /**
     * @return list<array{text: string, row_id: int}>
     */
    private function blacksmithRepairRows(Character $player): array
    {
        $rows = [];

        foreach ($this->repairs->damagedList($player->tg_id) as $row) {
            $rows[] = [
                'text' => __('telegram.npc.blacksmith.repair_row', [
                    'name' => TelegramHtml::escape($row->item_name),
                    'current' => $row->durability,
                    'max' => $row->max_durability,
                    'price' => $this->repairs->repairCost($row),
                ]),
                'row_id' => $row->id,
            ];
        }

        return $rows;
    }

    private function blacksmithScreen(TelegramResponder $responder, Character $player): void
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

        $this->editBlacksmithPanel(
            $responder,
            __('telegram.npc.blacksmith.offer'),
            CityKeyboard::blacksmith(),
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
