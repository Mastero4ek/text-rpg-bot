<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Bag\BagGemBuyAction;
use App\Actions\City\CityBuyerSellAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagItem;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\Backpack\BackpackService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CityMenuService;
use App\Services\Shop\ShopCatalog;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use Throwable;

final class BuyerHandler
{
    private const string SELL_SOURCE_BAG = 'bag';

    private const string SELL_SOURCE_BACKPACK = 'bp';

    public function __construct(
        private readonly BagCatalog $bagCatalog,
        private readonly BagGemBuyAction $buyGem,
        private readonly BagService $bag,
        private readonly BackpackService $backpack,
        private readonly CityBuyerSellAction $sellItem,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly ShopCatalog $shop,
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

        if ($data === 'city:buyer') {
            $this->buyerScreen($responder, $player);

            return;
        }

        if ($data === 'city:buyer:chest') {
            $this->buyerChest($responder, $player);

            return;
        }

        if (preg_match('/^city:buyer:buy:(.+)$/', $data, $m) === 1) {
            $this->buyerBuy($responder, $player, $m[1]);

            return;
        }

        if ($data === 'city:buyer:sell') {
            $this->sellListScreen($responder, $player);

            return;
        }

        if (preg_match('/^city:buyer:sell:(bp|bag):(\d+)$/', $data, $m) === 1) {
            $this->sellConfirmScreen($responder, $player, $m[1], (int) $m[2]);

            return;
        }

        if (preg_match('/^city:buyer:sell_yes:(bp|bag):(\d+)$/', $data, $m) === 1) {
            $this->sell($responder, $player, $m[1], (int) $m[2]);
        }
    }

    /**
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     */
    private function editBuyerPanel(TelegramResponder $responder, string $text, array $markup): void
    {
        $buyerImage = config('bot.buyer_tavern_image');

        if (is_string($buyerImage) && $buyerImage !== '' && is_file($buyerImage)) {
            try {
                $responder->editPhoto($buyerImage, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $responder->edit($text, $markup);
    }

    private function buyerBuy(TelegramResponder $responder, Character $player, string $catalogId): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $res = $this->buyGem->handle($player, $catalogId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editBuyerPanel(
                $responder,
                $this->buyerErrorText($res->error),
                $this->buyerErrorMarkup($player, $res->error),
            );

            return;
        }

        $this->editBuyerPanel(
            $responder,
            __('telegram.npc.buyer.bought', [
                'name' => TelegramHtml::escape($this->bagCatalog->findGem($catalogId)->name),
            ]),
            CityKeyboard::buyerChest($this->buyerRows($res->character)),
        );
    }

    private function buyerChest(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $items = $this->buyerRows($player);

        if (empty($items)) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.empty'),
                CityKeyboard::backToBuyer(),
            );

            return;
        }

        $this->editBuyerPanel(
            $responder,
            __('telegram.npc.buyer.chest'),
            CityKeyboard::buyerChest($items),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function buyerErrorMarkup(Character $player, ?string $error): array
    {
        if ($error === __('errors.no_buyer') || $error === __('errors.no_shop')) {
            return CityKeyboard::backToTavern();
        }

        $items = $this->buyerRows($player);

        if (empty($items)) {
            return CityKeyboard::backToBuyer();
        }

        return CityKeyboard::buyerChest($items);
    }

    private function buyerErrorText(?string $error): string
    {
        if ($error === __('errors.not_enough_silver')) {
            return __('telegram.npc.buyer.error.not_enough_silver');
        }

        if ($error === __('errors.not_enough_gold')) {
            return __('telegram.npc.buyer.error.not_enough_gold');
        }

        if ($error === __('errors.bag_full')) {
            return __('telegram.npc.buyer.error.bag_full');
        }

        if ($error === __('errors.gem_not_in_shop') || $error === __('errors.gem_not_found')) {
            return __('telegram.npc.buyer.error.unavailable');
        }

        if ($error === __('errors.no_buyer') || $error === __('errors.no_shop')) {
            return __('telegram.npc.buyer.error.no_buyer');
        }

        return TelegramResponder::errorMessage($error);
    }

    /**
     * @return list<array{text: string, catalog_id: string}>
     */
    private function buyerRows(Character $player): array
    {
        if ($player->city_id === null) {
            return [];
        }

        $rows = [];

        foreach ($this->cityQuery->bagNonPotionShopCatalogIds($player->city_id) as $catalogId) {
            if (! $this->bagCatalog->hasGem($catalogId)) {
                continue;
            }

            $gem = $this->bagCatalog->findGem($catalogId);

            if (! $gem->inShop) {
                continue;
            }

            $rows[] = [
                'text' => __('telegram.npc.buyer.row', [
                    'name' => TelegramHtml::escape($gem->name),
                    'price' => $gem->price,
                    'mark' => $this->currencyMark($gem->currency),
                ]),
                'catalog_id' => $gem->id,
            ];
        }

        return $rows;
    }

    private function buyerScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $this->editBuyerPanel(
            $responder,
            __('telegram.npc.buyer.offer'),
            CityKeyboard::buyerOffer(),
        );
    }

    private function currencyMark(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return '🥇';
        }

        return '🪙';
    }

    private function sell(TelegramResponder $responder, Character $player, string $source, int $rowId): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($source === self::SELL_SOURCE_BACKPACK) {
            $preview = $this->backpackSellPreview($player, $rowId);
        } else {
            $preview = $this->bagSellPreview($player, $rowId);
        }

        if ($preview === null) {
            $this->editBuyerPanel(
                $responder,
                TelegramResponder::errorMessage(__('errors.item_not_found')),
                CityKeyboard::backToBuyer(),
            );

            return;
        }

        if ($source === self::SELL_SOURCE_BACKPACK) {
            $res = $this->sellItem->handleBackpack($player, $rowId);
        } else {
            $res = $this->sellItem->handleBag($player, $rowId);
        }

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editBuyerPanel(
                $responder,
                TelegramResponder::errorMessage($res->error),
                CityKeyboard::backToBuyer(),
            );

            return;
        }

        $player = $res->character;
        $items = $this->sellableTradeRows($player);

        $this->editBuyerPanel(
            $responder,
            __('telegram.npc.buyer.sold', [
                'name' => TelegramHtml::escape($preview['name']),
                'price' => $preview['payout'],
                'mark' => $preview['mark'],
            ]),
            CityKeyboard::buyerSell($items),
        );
    }

    private function sellConfirmScreen(TelegramResponder $responder, Character $player, string $source, int $rowId): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($source === self::SELL_SOURCE_BACKPACK) {
            $preview = $this->backpackSellPreview($player, $rowId);
        } else {
            $preview = $this->bagSellPreview($player, $rowId);
        }

        if ($preview === null) {
            $this->editBuyerPanel(
                $responder,
                TelegramResponder::errorMessage(__('errors.item_not_found')),
                CityKeyboard::backToBuyer(),
            );

            return;
        }

        if ($preview['payout'] <= 0) {
            $text = __('telegram.npc.buyer.sell_confirm_zero', [
                'name' => TelegramHtml::escape($preview['name']),
                'mark' => $preview['mark'],
            ]);
        } else {
            $text = __('telegram.npc.buyer.sell_confirm', [
                'name' => TelegramHtml::escape($preview['name']),
                'price' => $preview['payout'],
                'mark' => $preview['mark'],
            ]);
        }

        $this->editBuyerPanel($responder, $text, CityKeyboard::buyerSellConfirm($source, $rowId));
    }

    private function sellListScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $items = $this->sellableTradeRows($player);

        if (empty($items)) {
            $text = __('telegram.npc.buyer.sell_empty');
        } else {
            $text = __('telegram.npc.buyer.sell');
        }

        $this->editBuyerPanel($responder, $text, CityKeyboard::buyerSell($items));
    }

    /**
     * @return list<array{text: string, source: string, row_id: int}>
     */
    private function sellableTradeRows(Character $player): array
    {
        $items = [];

        foreach ($this->backpack->sellableList($player->tg_id) as $row) {
            if (! $this->shop->hasItem($row->catalog_id)) {
                continue;
            }

            $def = $this->shop->findItem($row->catalog_id);
            $payout = $this->backpack->sellPayout($row);
            $mark = $this->currencyMark($def->currency);

            if ($row->max_durability !== null && $row->durability !== null) {
                $label = __('telegram.npc.buyer.sell_row_dur', [
                    'name' => $this->backpack->rowLabel($row),
                    'price' => $payout,
                    'mark' => $mark,
                    'current' => $row->durability,
                    'max' => $row->max_durability,
                ]);
            } else {
                $label = __('telegram.npc.buyer.sell_row', [
                    'name' => $this->backpack->rowLabel($row),
                    'price' => $payout,
                    'mark' => $mark,
                ]);
            }

            $items[] = [
                'text' => $label,
                'source' => self::SELL_SOURCE_BACKPACK,
                'row_id' => $row->id,
            ];
        }

        foreach ($this->bag->sellableLooseList($player->tg_id) as $row) {
            if ($row->kind === BagKindEnum::POTION) {
                if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                    continue;
                }
            } elseif (! $this->bagCatalog->hasGem($row->catalog_id)) {
                continue;
            }

            $payout = $this->bag->sellPayout($row);
            $mark = $this->currencyMark($this->bag->sellCurrency($row));
            $name = $this->bag->sellLabel($row);

            if ($row->kind === BagKindEnum::POTION && $row->quantity > 1) {
                $label = __('telegram.npc.buyer.sell_row_qty', [
                    'name' => $name,
                    'price' => $payout,
                    'mark' => $mark,
                    'qty' => $row->quantity,
                ]);
            } elseif ($row->kind === BagKindEnum::GEM && $row->durability !== null) {
                $gem = $this->bagCatalog->findGem($row->catalog_id);
                $label = __('telegram.npc.buyer.sell_row_dur', [
                    'name' => $name,
                    'price' => $payout,
                    'mark' => $mark,
                    'current' => $row->durability,
                    'max' => $gem->maxDurability,
                ]);
            } else {
                $label = __('telegram.npc.buyer.sell_row', [
                    'name' => $name,
                    'price' => $payout,
                    'mark' => $mark,
                ]);
            }

            $items[] = [
                'text' => $label,
                'source' => self::SELL_SOURCE_BAG,
                'row_id' => $row->id,
            ];
        }

        return $items;
    }

    /**
     * @return array{name: string, payout: int, mark: string}|null
     */
    private function backpackSellPreview(Character $player, int $rowId): ?array
    {
        $row = BackpackItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || $row->isEquipped()) {
            return null;
        }

        if (! $this->shop->hasItem($row->catalog_id)) {
            return null;
        }

        $def = $this->shop->findItem($row->catalog_id);

        return [
            'name' => $this->backpack->rowLabel($row),
            'payout' => $this->backpack->sellPayout($row),
            'mark' => $this->currencyMark($def->currency),
        ];
    }

    /**
     * @return array{name: string, payout: int, mark: string}|null
     */
    private function bagSellPreview(Character $player, int $rowId): ?array
    {
        $row = BagItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || $row->backpack_item_id !== null) {
            return null;
        }

        if ($row->kind === BagKindEnum::POTION) {
            if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                return null;
            }
        } elseif (! $this->bagCatalog->hasGem($row->catalog_id)) {
            return null;
        }

        return [
            'name' => $this->bag->sellLabel($row),
            'payout' => $this->bag->sellPayout($row),
            'mark' => $this->currencyMark($this->bag->sellCurrency($row)),
        ];
    }
}
