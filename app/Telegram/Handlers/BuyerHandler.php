<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Actions\Bag\BagGemBuyAction;
use App\Actions\City\CityBuyerSellAction;
use App\Actions\Telegram\FlashListPageEdgeAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\Backpack\BackpackCatalog;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagCatalog as BagCatalogModel;
use App\Models\Bag\BagItem;
use App\Models\Character\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Quests\BuyerQuest;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagCatalog;
use App\Services\Bag\BagService;
use App\Services\CityMenuService;
use App\Services\DailyContract\DailyContractService;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Shop\ShopCatalog;
use App\Support\Demo\DemoMode;
use App\Support\Gem\GemDef;
use App\Support\Gem\GemMfText;
use App\Support\PotionDef;
use App\Support\Telegram\TelegramHtml;
use App\Support\Telegram\TelegramPlayerGate;
use App\Support\Telegram\TelegramResponder;
use App\Support\Telegram\TelegramUpdate;
use App\Telegram\Keyboards\CityKeyboard;
use App\Telegram\Keyboards\PaginatedListKeyboard;
use App\Telegram\Keyboards\TelegramKeyboards;
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
        private readonly BuyerQuest $buyerQuest,
        private readonly CityBuyerSellAction $sellItem,
        private readonly CityMenuService $cityMenu,
        private readonly CityQuery $cityQuery,
        private readonly DailyContractService $dailyContracts,
        private readonly LoadoutService $loadout,
        private readonly QuestNpcPlacement $npcPlacement,
        private readonly ShopCatalog $shop,
        private readonly TelegramPlayerGate $gate,
    ) {}

    public function handleCallback(TelegramUpdate $update, TelegramResponder $responder): void
    {
        $data = $update->callbackData();
        $chestState = PaginatedListKeyboard::listState($data, 'city:buyer:chest');
        $sellState = PaginatedListKeyboard::listState($data, 'city:buyer:sell');

        if ($chestState !== null || $sellState !== null || $data === 'city:buyer:chest' || $data === 'city:buyer:sell') {
            $player = $this->gate->requireCityPlayer($update, $responder);

            if ($player === false) {
                $responder->answerCallback();

                return;
            }

            if ($chestState !== null) {
                $this->buyerChest($responder, $player, $chestState['filter'], $chestState['page']);

                return;
            }

            if ($data === 'city:buyer:chest') {
                $this->buyerChest($responder, $player, 'all', 1);

                return;
            }

            if ($sellState !== null) {
                $this->sellListScreen($responder, $player, $sellState['filter'], $sellState['page']);

                return;
            }

            $this->sellListScreen($responder, $player, 'all', 1);

            return;
        }

        $responder->answerCallback();
        $player = $this->gate->requireCityPlayer($update, $responder);

        if ($player === false) {
            return;
        }

        if ($data === 'city:buyer') {
            $this->buyerScreen($responder, $player);

            return;
        }

        if (preg_match('/^city:buyer:chest:view:([A-Za-z0-9_]+):([A-Za-z0-9_]+)$/', $data, $m) === 1) {
            $this->buyerChestView($responder, $player, $m[1], $m[2]);

            return;
        }

        if (preg_match('/^city:buyer:buy:(.+)$/', $data, $m) === 1) {
            $this->buyerBuy($responder, $player, $m[1]);

            return;
        }

        if (preg_match('/^city:buyer:sell:view:(bp|bag):(\d+):([A-Za-z0-9_]+)$/', $data, $m) === 1) {
            $this->sellView($responder, $player, $m[1], (int) $m[2], $m[3]);

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

    private function backpackCatalogImagePath(string $catalogId): ?string
    {
        $model = BackpackCatalog::query()->find($catalogId);

        if (! $model instanceof BackpackCatalog) {
            return null;
        }

        return $model->localImagePath();
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

    private function bagCatalogImagePath(string $catalogId): ?string
    {
        $model = BagCatalogModel::query()->find($catalogId);

        if (! $model instanceof BagCatalogModel) {
            return null;
        }

        return $model->localImagePath();
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

        $filter = 'all';
        $res = $this->buyGem->handle($player, $catalogId);

        if (! $res->ok || ! $res->character instanceof Character) {
            $this->editBuyerPanel(
                $responder,
                $this->buyerErrorText($res->error),
                $this->buyerErrorMarkup($player, $res->error, $catalogId, $filter),
            );

            return;
        }

        $this->editBuyerPanel(
            $responder,
            __('telegram.npc.buyer.bought', [
                'name' => TelegramHtml::escape($this->bagCatalog->findGem($catalogId)->name),
            ]),
            CityKeyboard::buyerChest(
                $this->buyerRows($res->character, 'all'),
                'all',
                1,
                count($this->buyerRows($res->character, 'all')),
            ),
        );
    }

    private function buyerChest(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $responder->answerCallback();
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $filter = $this->chestFilter($filter);
        $allItems = $this->buyerRows($player, 'all');

        if ($filter === 'all') {
            $items = $allItems;
        } else {
            $items = $this->buyerRows($player, $filter);
        }

        $edge = PaginatedListKeyboard::isOutOfRange($page, count($items));
        $markup = CityKeyboard::buyerChest($items, $filter, $page, count($allItems));

        $responder->answerCallback();

        if ($edge && ! empty($items)) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('telegram.npc.buyer.empty'),
                __('telegram.npc.buyer.chest'),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if (empty($items)) {
            $text = __('telegram.npc.buyer.empty');
        } else {
            $text = __('telegram.npc.buyer.chest');
        }

        $this->editBuyerPanel($responder, $text, $markup);
    }

    private function buyerChestView(
        TelegramResponder $responder,
        Character $player,
        string $catalogId,
        string $filter,
    ): void {
        $city = $this->cityMenu->currentCity($player);
        $filter = $this->chestFilter($filter);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if (
            ! $this->cityQuery->bagInCityShop($city->id, $catalogId)
            || ! $this->bagCatalog->hasGem($catalogId)
        ) {
            $items = $this->buyerRows($player, $filter);
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.unavailable'),
                CityKeyboard::buyerChest(
                    $items,
                    $filter,
                    1,
                    count($this->buyerRows($player, 'all')),
                ),
            );

            return;
        }

        $gem = $this->bagCatalog->findGem($catalogId);

        if (! $gem->inShop) {
            $items = $this->buyerRows($player, $filter);
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.unavailable'),
                CityKeyboard::buyerChest(
                    $items,
                    $filter,
                    1,
                    count($this->buyerRows($player, 'all')),
                ),
            );

            return;
        }

        $text = $this->buyerGemCardText($gem, $gem->maxDurability)
            . "\n\n"
            . __('telegram.npc.buyer.gem_card_price', [
                'mark' => $this->currencyMark($gem->currency),
                'price' => $gem->price,
            ]);
        $markup = CityKeyboard::buyerChestCard($catalogId, $filter);
        $imagePath = $this->bagCatalogImagePath($catalogId);

        if (is_string($imagePath)) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $this->editBuyerPanel($responder, $text, $markup);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private function buyerErrorMarkup(Character $player, ?string $error, string $catalogId, string $filter): array
    {
        if ($error === __('errors.no_buyer') || $error === __('errors.no_shop')) {
            return CityKeyboard::backToTavern();
        }

        if (in_array($error, [
            __('errors.not_enough_silver'),
            __('errors.not_enough_gold'),
            __('errors.gem_not_in_shop'),
            __('errors.gem_not_found'),
        ], true)) {
            $items = $this->buyerRows($player, $filter);

            return CityKeyboard::buyerChest(
                $items,
                $filter,
                1,
                count($this->buyerRows($player, 'all')),
            );
        }

        return CityKeyboard::buyerChestCard($catalogId, $filter);
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

    private function buyerGemCardText(GemDef $gem, int $current): string
    {
        $description = $gem->description;

        if ($description === null || $description === '') {
            return __('telegram.npc.buyer.gem_card_no_desc', [
                'name' => TelegramHtml::escape($gem->name),
                'mf' => GemMfText::forDef($gem),
                'current' => $current,
                'max' => $gem->maxDurability,
            ]);
        }

        return __('telegram.npc.buyer.gem_card', [
            'name' => TelegramHtml::escape($gem->name),
            'description' => TelegramHtml::escape($description),
            'mf' => GemMfText::forDef($gem),
            'current' => $current,
            'max' => $gem->maxDurability,
        ]);
    }

    /**
     * @return list<array{text: string, catalog_id: string}>
     */
    private function buyerRows(Character $player, string $filter): array
    {
        if ($player->city_id === null) {
            return [];
        }

        if ($filter === 'charms') {
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
                ]),
                'catalog_id' => $gem->id,
            ];
        }

        return $rows;
    }

    private function buyerScreen(TelegramResponder $responder, Character $player): void
    {
        $city = $this->cityMenu->currentCity($player);

        $back = $this->npcPlacement->npcBackCallback($player, NpcEnum::Buyer);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToCallback($back),
            );

            return;
        }

        if ($this->buyerQuest->awaitsTurnIn($player)) {
            $this->editBuyerPanel(
                $responder,
                $this->buyerQuest->turnInSpeech($player),
                TelegramKeyboards::buyerTurnIn(),
            );

            return;
        }

        $hasDailyReady = $this->dailyContracts
            ->turnInableForIssuer($player, $city, NpcEnum::Buyer)
            ->isNotEmpty();

        $this->editBuyerPanel(
            $responder,
            __('telegram.npc.buyer.offer'),
            CityKeyboard::buyerOfferWithDaily($hasDailyReady, $back),
        );
    }

    private function chestFilter(string $filter): string
    {
        if (in_array($filter, ['all', 'gems', 'charms'], true)) {
            return $filter;
        }

        return 'all';
    }

    private function currencyMark(CurrencyEnum $currency): string
    {
        if ($currency === CurrencyEnum::GOLD) {
            return '🥇';
        }

        return '🪙';
    }

    private function potionEffectMark(ProfileEnum $profile): string
    {
        if ($profile === ProfileEnum::STAMINA) {
            return '💚';
        }

        return '❤️';
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
        $items = $this->sellableTradeRows($player, 'all');

        $soldText = __('telegram.npc.buyer.sold', [
            'name' => TelegramHtml::escape($preview['name']),
            'price' => $preview['payout'],
            'mark' => $preview['mark'],
        ]);

        $this->editBuyerPanel(
            $responder,
            DemoMode::withBuyerNoPayNotice($player, $soldText),
            CityKeyboard::buyerSell($items, 'all', 1, count($items)),
        );
    }

    /**
     * @return list<array{text: string, source: string, row_id: int}>
     */
    private function sellableTradeRows(Character $player, string $filter): array
    {
        $items = [];

        if ($filter === 'all' || $filter === 'bp') {
            foreach ($this->backpack->sellableList($player->tg_id) as $row) {
                if (! $this->shop->hasItem($row->catalog_id)) {
                    continue;
                }

                if (
                    $this->buyerQuest->isAmuletSellLocked($player)
                    && $row->catalog_id === $this->buyerQuest->amuletCatalogId()
                ) {
                    continue;
                }

                if ($row->max_durability !== null && $row->durability !== null) {
                    $label = __('telegram.npc.buyer.sell_row_dur', [
                        'name' => $this->backpack->rowLabel($row),
                    ]);
                } else {
                    $label = __('telegram.npc.buyer.sell_row', [
                        'name' => $this->backpack->rowLabel($row),
                    ]);
                }

                $items[] = [
                    'text' => $label,
                    'source' => self::SELL_SOURCE_BACKPACK,
                    'row_id' => $row->id,
                ];
            }
        }

        if ($filter === 'all' || $filter === 'bag') {
            foreach ($this->bag->sellableLooseList($player->tg_id) as $row) {
                if ($row->kind === BagKindEnum::POTION) {
                    if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                        continue;
                    }
                } elseif (! $this->bagCatalog->hasGem($row->catalog_id)) {
                    continue;
                }

                $name = $this->bag->sellLabel($row);

                if ($row->kind === BagKindEnum::POTION && $row->quantity > 1) {
                    $label = __('telegram.npc.buyer.sell_row_qty', [
                        'name' => $name,
                        'qty' => $row->quantity,
                    ]);
                } else {
                    $label = __('telegram.npc.buyer.sell_row', [
                        'name' => $name,
                    ]);
                }

                $items[] = [
                    'text' => $label,
                    'source' => self::SELL_SOURCE_BAG,
                    'row_id' => $row->id,
                ];
            }
        }

        return $items;
    }

    private function sellBagCardText(BagItem $row): string
    {
        if ($row->kind === BagKindEnum::POTION) {
            $potion = $this->bagCatalog->findPotion($row->catalog_id);

            return $this->sellPotionCardText($potion, $row->quantity);
        }

        $gem = $this->bagCatalog->findGem($row->catalog_id);
        $current = $row->durability;

        if ($current === null) {
            $current = $gem->maxDurability;
        }

        return $this->buyerGemCardText($gem, $current);
    }

    private function sellFilter(string $filter): string
    {
        if (in_array($filter, ['all', 'bag', 'bp'], true)) {
            return $filter;
        }

        return 'all';
    }

    private function sellListScreen(TelegramResponder $responder, Character $player, string $filter, int $page): void
    {
        $city = $this->cityMenu->currentCity($player);

        if (! $city instanceof City || ! $city->has_buyer) {
            $responder->answerCallback();
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        $filter = $this->sellFilter($filter);
        $allItems = $this->sellableTradeRows($player, 'all');

        if ($filter === 'all') {
            $items = $allItems;
        } else {
            $items = $this->sellableTradeRows($player, $filter);
        }

        $edge = PaginatedListKeyboard::isOutOfRange($page, count($items));
        $markup = CityKeyboard::buyerSell($items, $filter, $page, count($allItems));

        $responder->answerCallback();

        if ($edge && ! empty($items)) {
            app(FlashListPageEdgeAction::class)->handle(
                $responder,
                __('telegram.npc.buyer.sell_empty'),
                __('telegram.npc.buyer.sell'),
                $markup,
            );

            return;
        }

        app(FlashListPageEdgeAction::class)->touch($responder->chatId(), $responder->messageId());

        if (empty($items)) {
            $text = __('telegram.npc.buyer.sell_empty');
        } else {
            $text = __('telegram.npc.buyer.sell');
        }

        $this->editBuyerPanel($responder, $text, $markup);
    }

    private function sellPotionCardText(PotionDef $potion, int $quantity): string
    {
        $description = $potion->description;

        if ($description === null || $description === '') {
            return __('telegram.npc.buyer.sell_potion_card_no_desc', [
                'name' => TelegramHtml::escape($potion->name),
                'effect_mark' => $this->potionEffectMark($potion->profile),
                'effect' => $potion->effectValue,
                'qty' => $quantity,
            ]);
        }

        return __('telegram.npc.buyer.sell_potion_card', [
            'name' => TelegramHtml::escape($potion->name),
            'description' => TelegramHtml::escape($description),
            'effect_mark' => $this->potionEffectMark($potion->profile),
            'effect' => $potion->effectValue,
            'qty' => $quantity,
        ]);
    }

    private function sellPriceLine(Character $player, int $payout, string $mark): string
    {
        if ($payout <= 0) {
            $line = __('telegram.npc.buyer.sell_card_price_zero', [
                'mark' => $mark,
            ]);
        } else {
            $line = __('telegram.npc.buyer.sell_card_price', [
                'mark' => $mark,
                'price' => $payout,
            ]);
        }

        return DemoMode::withBuyerNoPayNotice($player, $line);
    }

    private function sellView(
        TelegramResponder $responder,
        Character $player,
        string $source,
        int $rowId,
        string $filter,
    ): void {
        $city = $this->cityMenu->currentCity($player);
        $filter = $this->sellFilter($filter);

        if (! $city instanceof City || ! $city->has_buyer) {
            $this->editBuyerPanel(
                $responder,
                __('telegram.npc.buyer.error.no_buyer'),
                CityKeyboard::backToTavern(),
            );

            return;
        }

        if ($source === self::SELL_SOURCE_BACKPACK) {
            $row = BackpackItem::query()
                ->where('id', $rowId)
                ->where('tg_id', $player->tg_id)
                ->first();

            if (
                ! $row instanceof BackpackItem
                || $row->isEquipped()
                || ! $this->shop->hasItem($row->catalog_id)
            ) {
                $this->editBuyerPanel(
                    $responder,
                    TelegramResponder::errorMessage(__('errors.item_not_found')),
                    CityKeyboard::buyerSell(
                        $this->sellableTradeRows($player, $filter),
                        $filter,
                        1,
                        count($this->sellableTradeRows($player, 'all')),
                    ),
                );

                return;
            }

            if (
                $this->buyerQuest->isAmuletSellLocked($player)
                && $row->catalog_id === $this->buyerQuest->amuletCatalogId()
            ) {
                $this->editBuyerPanel(
                    $responder,
                    TelegramResponder::errorMessage(__('errors.cannot_sell')),
                    CityKeyboard::buyerSell(
                        $this->sellableTradeRows($player, $filter),
                        $filter,
                        1,
                        count($this->sellableTradeRows($player, 'all')),
                    ),
                );

                return;
            }

            $def = $this->shop->findItem($row->catalog_id);
            $payout = $this->backpack->sellPayout($row);
            $mark = $this->currencyMark($def->currency);
            $text = $this->loadout->gearShopCardText($row, $def, true)
                . "\n\n"
                . $this->sellPriceLine($player, $payout, $mark);
            $markup = CityKeyboard::buyerSellConfirm($source, $rowId, $filter);
            $imagePath = $this->backpackCatalogImagePath($row->catalog_id);

            if (is_string($imagePath)) {
                try {
                    $responder->editPhoto($imagePath, $text, $markup);

                    return;
                } catch (Throwable) {
                }
            }

            $this->editBuyerPanel($responder, $text, $markup);

            return;
        }

        $row = BagItem::query()
            ->where('id', $rowId)
            ->where('tg_id', $player->tg_id)
            ->first();

        if ($row === null || $row->backpack_item_id !== null) {
            $this->editBuyerPanel(
                $responder,
                TelegramResponder::errorMessage(__('errors.item_not_found')),
                CityKeyboard::buyerSell(
                    $this->sellableTradeRows($player, $filter),
                    $filter,
                    1,
                    count($this->sellableTradeRows($player, 'all')),
                ),
            );

            return;
        }

        if ($row->kind === BagKindEnum::POTION) {
            if (! $this->bagCatalog->hasPotion($row->catalog_id)) {
                $this->editBuyerPanel(
                    $responder,
                    TelegramResponder::errorMessage(__('errors.item_not_found')),
                    CityKeyboard::buyerSell(
                        $this->sellableTradeRows($player, $filter),
                        $filter,
                        1,
                        count($this->sellableTradeRows($player, 'all')),
                    ),
                );

                return;
            }
        } elseif (! $this->bagCatalog->hasGem($row->catalog_id)) {
            $this->editBuyerPanel(
                $responder,
                TelegramResponder::errorMessage(__('errors.item_not_found')),
                CityKeyboard::buyerSell(
                    $this->sellableTradeRows($player, $filter),
                    $filter,
                    1,
                    count($this->sellableTradeRows($player, 'all')),
                ),
            );

            return;
        }

        $payout = $this->bag->sellPayout($row);
        $mark = $this->currencyMark($this->bag->sellCurrency($row));
        $text = $this->sellBagCardText($row)
            . "\n\n"
            . $this->sellPriceLine($player, $payout, $mark);
        $markup = CityKeyboard::buyerSellConfirm($source, $rowId, $filter);
        $imagePath = $this->bagCatalogImagePath($row->catalog_id);

        if (is_string($imagePath)) {
            try {
                $responder->editPhoto($imagePath, $text, $markup);

                return;
            } catch (Throwable) {
            }
        }

        $this->editBuyerPanel($responder, $text, $markup);
    }
}
