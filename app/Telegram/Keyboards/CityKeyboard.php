<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Enemy\EnemyKindEnum;
use App\Enums\Equipment\TypeEnum;
use App\Enums\Npc\NpcEnum;
use App\Enums\StatKeyEnum;
use App\Models\City;

final class CityKeyboard
{
    use BuildsInlineKeyboard;

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function arena(City $city): array
    {
        $rows = [];

        if ($city->has_training_room) {
            $rows[] = [self::cb(__('telegram.btn.training'), 'city:training')];
        }

        if ($city->has_fights_list) {
            $rows[] = [self::cb(__('telegram.btn.fights'), 'city:fights')];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToArena(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:lists')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function boardList(array $items, string $filter, int $page, int $totalCount): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            self::boardFilters(),
            $filter,
            $page,
            'city:board',
            'city:home',
            null,
            $totalCount,
        );
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function boardFilters(): array
    {
        return [
            ['id' => 'all', 'label' => __('telegram.btn.filter_all')],
            ['id' => 'orders', 'label' => __('telegram.btn.filter_board_orders')],
            ['id' => 'asks', 'label' => __('telegram.btn.filter_board_asks')],
        ];
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @param  array{text: string, callback_data: string, style?: string}|null  $extraFooter
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function fightsList(array $items, int $page, ?array $extraFooter = null): array
    {
        $markup = PaginatedListKeyboard::markup(
            $items,
            [],
            'all',
            $page,
            'pvp',
            null,
            null,
            count($items),
        );

        $rows = $markup['inline_keyboard'];
        $back = self::cbDanger(__('telegram.btn.back'), 'city:lists');

        if ($extraFooter === null) {
            $rows[] = [$back];
        } elseif (array_key_exists('style', $extraFooter)) {
            $rows[] = [
                $back,
                [
                    'text' => $extraFooter['text'],
                    'callback_data' => $extraFooter['callback_data'],
                    'style' => $extraFooter['style'],
                ],
            ];
        } else {
            $rows[] = [
                $back,
                self::cb($extraFooter['text'], $extraFooter['callback_data']),
            ];
        }

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToCity(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'menu:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToGates(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:gates')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToBlacksmith(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:blacksmith')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToBuyer(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:buyer')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToHealer(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:healer')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToOverseer(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:overseer')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToTavern(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:tavern')],
        ]);
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $enemies
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function trainingPick(array $enemies, string $filter, int $page): array
    {
        $items = [];

        foreach ($enemies as $enemy) {
            $items[] = [
                'text' => $enemy['text'],
                'callback_data' => 'city:training:start:' . $enemy['catalog_id'],
            ];
        }

        return PaginatedListKeyboard::markup(
            $items,
            [],
            $filter,
            $page,
            'city:training',
            'city:lists',
            null,
            count($items),
        );
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithGear(array $items, string $filter, int $page, int $totalCount): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'text' => $item['text'],
                'callback_data' => 'city:blacksmith:gear:view:' . $item['catalog_id'] . ':' . $filter,
            ];
        }

        return PaginatedListKeyboard::markup(
            $rows,
            self::gearTypeFilters(),
            $filter,
            $page,
            'city:blacksmith:gear',
            'city:blacksmith',
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithGearCard(string $catalogId, string $filter): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.back'), 'city:blacksmith:gear:list:' . $filter . ':1'),
                self::cbSuccess(__('telegram.btn.buy'), 'city:blacksmith:buy:' . $catalogId),
            ],
        ]);
    }

    /**
     * @param  list<array{text: string, row_id: int}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithRepair(
        array $items,
        string $filter,
        int $page,
        ?string $repairAllBtn,
        int $totalCount,
    ): array {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'text' => $item['text'],
                'callback_data' => 'city:blacksmith:repair:view:' . $item['row_id'] . ':' . $filter,
            ];
        }

        $markup = PaginatedListKeyboard::markup(
            $rows,
            self::gearTypeFilters(),
            $filter,
            $page,
            'city:blacksmith:repair',
            null,
            null,
            $totalCount,
        );

        if ($repairAllBtn !== null) {
            $markup['inline_keyboard'][] = [
                self::cbDanger(__('telegram.btn.back'), 'city:blacksmith'),
                self::cbSuccess($repairAllBtn, 'city:blacksmith:repair_all'),
            ];
        } else {
            $markup['inline_keyboard'][] = [
                self::cbDanger(__('telegram.btn.back'), 'city:blacksmith'),
            ];
        }

        return $markup;
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithRepairAllConfirm(string $filter): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'city:blacksmith:repair:list:' . $filter . ':1'),
                self::cbSuccess(__('telegram.btn.yes'), 'city:blacksmith:repair_all:yes'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithRepairCard(int $rowId, string $filter): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.back'), 'city:blacksmith:repair:list:' . $filter . ':1'),
                self::cbSuccess(__('telegram.btn.repair'), 'city:blacksmith:repair:do:' . $rowId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerOffer(): array
    {
        return self::healerOfferWithDaily(false, 'city:tavern');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerOfferWithDaily(bool $hasDailyReady, string $backCallback): array
    {
        $rows = [];

        $rows[] = [self::cb(__('telegram.btn.potions'), 'city:healer:potions')];

        if ($hasDailyReady) {
            $rows[] = [self::cbSuccess(__('telegram.daily.btn_contracts'), 'daily:healer:list')];
        }

        $rows[] = [
            self::cbDanger(__('telegram.btn.back'), $backCallback),
            self::cbSuccess(__('telegram.btn.heal'), 'city:healer:heal'),
        ];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerHealConfirm(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'city:healer'),
                self::cbSuccess(__('telegram.btn.yes'), 'city:healer:heal:yes'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmith(): array
    {
        return self::blacksmithWithDaily(false, 'city:tavern');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithWithDaily(bool $hasDailyReady, string $backCallback): array
    {
        $rows = [];

        $rows[] = [self::cb(__('telegram.btn.gear'), 'city:blacksmith:gear')];
        $rows[] = [self::cb(__('telegram.btn.repair'), 'city:blacksmith:repair')];
        $rows[] = self::dailyBackRow($hasDailyReady, 'daily:blacksmith:list', $backCallback);

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerOffer(): array
    {
        return self::buyerOfferWithDaily(false, 'city:tavern');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerOfferWithDaily(bool $hasDailyReady, string $backCallback): array
    {
        $rows = [];

        $rows[] = [self::cb(__('telegram.btn.chest'), 'city:buyer:chest')];
        $rows[] = [self::cb(__('telegram.btn.sell'), 'city:buyer:sell')];
        $rows[] = self::dailyBackRow($hasDailyReady, 'daily:buyer:list', $backCallback);

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserOfferWithDaily(bool $hasDailyReady, string $backCallback): array
    {
        return self::inline([
            self::dailyBackRow($hasDailyReady, 'daily:bruiser:list', $backCallback),
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function npcOfferWithDaily(
        NpcEnum $issuer,
        bool $hasDailyReady,
        int $statPoints,
        string $backCallback,
    ): array {
        return match ($issuer) {
            NpcEnum::Buyer => self::buyerOfferWithDaily($hasDailyReady, $backCallback),
            NpcEnum::Healer => self::healerOfferWithDaily($hasDailyReady, $backCallback),
            NpcEnum::Blacksmith => self::blacksmithWithDaily($hasDailyReady, $backCallback),
            NpcEnum::Overseer => self::overseerWithDaily($hasDailyReady, $statPoints, $backCallback),
            NpcEnum::Barkeeper => self::barkeeperOfferWithDaily($hasDailyReady, $backCallback),
            NpcEnum::Bruiser => self::bruiserOfferWithDaily($hasDailyReady, $backCallback),
        };
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function barkeeperOfferWithDaily(bool $hasDailyReady, string $backCallback): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.lucky_barrel'), 'city:barkeeper:game')],
            self::dailyBackRow($hasDailyReady, 'daily:barkeeper:list', $backCallback),
        ]);
    }

    /**
     * @param  list<int>  $stakes
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function luckyBarrelStake(array $stakes): array
    {
        $stakeRow = [];

        foreach ($stakes as $stake) {
            $stakeRow[] = self::cb(
                __('telegram.btn.lucky_barrel_stake', ['n' => $stake]),
                'city:barkeeper:game:stake:' . $stake,
            );
        }

        return self::inline([
            $stakeRow,
            [self::cbDanger(__('telegram.btn.back'), 'city:barkeeper')],
        ]);
    }

    /**
     * @param  list<int>  $openedBarrels
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function luckyBarrelPlay(array $openedBarrels, int $currentStep): array
    {
        $barrelRow = [];

        for ($i = 1; $i <= 5; $i++) {
            if (in_array($i, $openedBarrels, true)) {
                $barrelRow[] = self::cb('❌', 'city:barkeeper:game:open:' . $i);
            } else {
                $barrelRow[] = self::cb('🛢️', 'city:barkeeper:game:open:' . $i);
            }
        }

        $rows = [$barrelRow];

        if ($currentStep >= 1) {
            $rows[] = [
                self::cbDanger(__('telegram.btn.back'), 'city:barkeeper:game:back'),
                self::cbSuccess(__('telegram.btn.lucky_barrel_cash'), 'city:barkeeper:game:cash'),
            ];
        } else {
            $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:barkeeper:game:back')];
        }

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function luckyBarrelEnd(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.lucky_barrel_again'), 'city:barkeeper:game')],
            [self::cbDanger(__('telegram.btn.back'), 'city:barkeeper')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToCallback(string $callback): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), $callback)],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToNpc(NpcEnum $issuer): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:' . $issuer->value)],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function dailyNpcTurnIn(NpcEnum $issuer, int $contractId, string $laterCallback): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.daily.btn_later'), $laterCallback),
                self::cbSuccess(__('telegram.daily.btn_give'), 'daily:' . $issuer->value . ':turnin:' . $contractId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function dailyOfferCard(int $contractId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.daily.btn_refuse'), 'city:board'),
                self::cbSuccess(__('telegram.daily.btn_accept'), 'daily:accept:' . $contractId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function boardBack(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'city:board')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function dailyBuyerTurnIn(int $contractId): array
    {
        return self::dailyNpcTurnIn(NpcEnum::Buyer, $contractId, 'city:tavern');
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function forestPick(array $items, string $filter, int $page): array
    {
        return self::pveZonePick($items, $filter, $page, 'city:forest');
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function pveZonePick(array $items, string $filter, int $page, string $listPrefix): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            [],
            $filter,
            $page,
            $listPrefix,
            'city:gates',
            null,
            count($items),
        );
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerChest(array $items, string $filter, int $page, int $totalCount): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'text' => $item['text'],
                'callback_data' => 'city:buyer:chest:view:' . $item['catalog_id'] . ':' . $filter,
            ];
        }

        return PaginatedListKeyboard::markup(
            $rows,
            self::buyerChestFilters(),
            $filter,
            $page,
            'city:buyer:chest',
            'city:buyer',
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerChestCard(string $catalogId, string $filter): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.back'), 'city:buyer:chest:list:' . $filter . ':1'),
                self::cbSuccess(__('telegram.btn.buy'), 'city:buyer:buy:' . $catalogId),
            ],
        ]);
    }

    /**
     * @param  list<array{text: string, source: string, row_id: int}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerSell(array $items, string $filter, int $page, int $totalCount): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'text' => $item['text'],
                'callback_data' => 'city:buyer:sell:view:' . $item['source'] . ':' . $item['row_id'] . ':' . $filter,
            ];
        }

        return PaginatedListKeyboard::markup(
            $rows,
            self::bagBackpackFilters(),
            $filter,
            $page,
            'city:buyer:sell',
            'city:buyer',
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerSellConfirm(string $source, int $rowId, string $filter): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'city:buyer:sell:list:' . $filter . ':1'),
                self::cbSuccess(__('telegram.btn.yes'), 'city:buyer:sell_yes:' . $source . ':' . $rowId),
            ],
        ]);
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $potions
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerPotions(array $potions, string $filter, int $page, int $totalCount): array
    {
        $rows = [];

        foreach ($potions as $potion) {
            $rows[] = [
                'text' => $potion['text'],
                'callback_data' => 'city:healer:potion:view:' . $potion['catalog_id'],
            ];
        }

        return PaginatedListKeyboard::markup(
            $rows,
            self::potionFilters(),
            $filter,
            $page,
            'city:healer:potions',
            'city:healer',
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerPotionCard(string $catalogId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.back'), 'city:healer:potions'),
                self::cbSuccess(__('telegram.btn.buy'), 'city:healer:potion:buy:' . $catalogId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function boardRefreshed(string $cityName): array
    {
        return self::idleSession($cityName);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function idleSession(string $cityName): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.btn.idle_to_city', ['name' => $cityName]), 'city:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function toCityHub(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.btn.to_city'), 'city:hub')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function jailReleased(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.btn.to_city'), 'city:home')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $pveRows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gates(City $city, string $portalLabel, array $pveRows): array
    {
        $rows = [];

        if ($city->has_portal) {
            $rows[] = [self::cb($portalLabel, 'city:portal')];
        }

        foreach ($pveRows as $pve) {
            $rows[] = [self::cb($pve['text'], $pve['callback_data'])];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerStats(int $statPoints): array
    {
        return self::overseerWithDaily(false, $statPoints, 'city:tavern');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerWithDaily(bool $hasDailyReady, int $statPoints, string $backCallback): array
    {
        $rows = [];

        if ($statPoints > 0) {
            $rows[] = [
                self::cb(__('profile.btn_str'), 'city:overseer:stat:' . StatKeyEnum::STRENGTH->value),
                self::cb(__('profile.btn_agi'), 'city:overseer:stat:' . StatKeyEnum::AGILITY->value),
            ];
            $rows[] = [
                self::cb(__('profile.btn_inst'), 'city:overseer:stat:' . StatKeyEnum::INSTINCT->value),
                self::cb(__('profile.btn_vit'), 'city:overseer:stat:' . StatKeyEnum::VITALITY->value),
            ];
        }

        if ($hasDailyReady) {
            $rows[] = [self::cbSuccess(__('telegram.daily.btn_contracts'), 'daily:overseer:list')];
        }

        $rows[] = [
            self::cbDanger(__('telegram.btn.back'), $backCallback),
            self::cbSuccess(__('telegram.btn.reset_stats'), 'city:overseer:reset'),
        ];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerResetConfirm(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'city:overseer'),
                self::cbSuccess(__('telegram.btn.yes'), 'city:overseer:reset_yes'),
            ],
        ]);
    }

    /**
     * @param  iterable<int, City>  $targets
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function portalTargets(iterable $targets, string $filter, int $page, int $totalCount): array
    {
        $rows = [];

        foreach ($targets as $target) {
            $rows[] = [
                'text' => __('telegram.location.portal_row', [
                    'name' => $target->name,
                ]),
                'callback_data' => 'portal:' . $target->id,
            ];
        }

        return PaginatedListKeyboard::markup(
            $rows,
            self::portalFilters(),
            $filter,
            $page,
            'portal',
            'city:gates',
            null,
            $totalCount,
        );
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function bagBackpackFilters(): array
    {
        return [
            ['id' => 'all', 'label' => __('telegram.btn.filter_all')],
            ['id' => 'bag', 'label' => __('telegram.btn.filter_bag')],
            ['id' => 'bp', 'label' => __('telegram.btn.filter_backpack')],
        ];
    }

    public static function enemyKindFromFilter(string $filter): ?EnemyKindEnum
    {
        if ($filter === 'fixed') {
            return EnemyKindEnum::FIXED;
        }

        if ($filter === 'mirror') {
            return EnemyKindEnum::MIRROR;
        }

        return null;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function gearTypeFilters(): array
    {
        return [
            ['id' => 'WEAPON', 'label' => __('telegram.btn.filter_weapon')],
            ['id' => 'ARMOR', 'label' => __('telegram.btn.filter_armor')],
            ['id' => 'JEWELRY', 'label' => __('telegram.btn.filter_jewelry')],
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function buyerChestFilters(): array
    {
        return [
            ['id' => 'all', 'label' => __('telegram.btn.filter_all')],
            ['id' => 'gems', 'label' => __('telegram.btn.filter_chest_gems')],
            ['id' => 'charms', 'label' => __('telegram.btn.filter_chest_charms')],
        ];
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function portalFilters(): array
    {
        return [
            ['id' => 'all', 'label' => __('telegram.btn.filter_all')],
            ['id' => 'free', 'label' => __('telegram.btn.filter_portal_free')],
            ['id' => 'paid', 'label' => __('telegram.btn.filter_portal_paid')],
        ];
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function portalPvpConfirm(int $targetCityId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.portal_stay'), 'city:portal'),
                self::cbSuccess(__('telegram.btn.portal_leave'), 'portal:yes:' . $targetCityId),
            ],
        ]);
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    public static function potionFilters(): array
    {
        return [
            ['id' => 'all', 'label' => __('telegram.btn.filter_all')],
            ['id' => 'heal', 'label' => __('telegram.btn.filter_potion_heal')],
            ['id' => 'stam', 'label' => __('telegram.btn.filter_potion_stamina')],
        ];
    }

    public static function gearTypeFromFilter(string $filter): ?TypeEnum
    {
        if ($filter === 'WEAPON' || $filter === 'wpn') {
            return TypeEnum::WEAPON;
        }

        if ($filter === 'ARMOR' || $filter === 'arm') {
            return TypeEnum::ARMOR;
        }

        if ($filter === 'JEWELRY' || $filter === 'jwl') {
            return TypeEnum::JEWELRY;
        }

        return null;
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function services(City $city, string $boardLabel, string $listsLabel): array
    {
        $rows = [
            [self::cb(__('telegram.btn.gates'), 'city:gates')],
            [self::cb(__('telegram.btn.tavern'), 'city:tavern')],
        ];

        if ($city->has_quest_board) {
            $rows[] = [self::cb($boardLabel, 'city:board')];
        }

        $rows[] = [self::cb($listsLabel, 'city:lists')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function lists(City $city, string $arenaLabel): array
    {
        $rows = [];

        if ($city->has_training_room) {
            $rows[] = [self::cb(__('telegram.btn.training'), 'city:training')];
        }

        $rows[] = [self::cb($arenaLabel, 'city:arena')];
        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @param  list<string>  $dailyNpc  barkeeper|healer|blacksmith|buyer|overseer with turn-inable daily
     * @param  list<string>  $hiddenNpc  issuers parked outside tavern by accepted delivery
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function tavern(City $city, array $dailyNpc, array $hiddenNpc): array
    {
        $rows = [];

        if ($city->has_barkeeper && ! in_array('barkeeper', $hiddenNpc, true)) {
            $rows[] = [self::cb(self::tavernNpcLabel('barkeeper', $dailyNpc), 'city:barkeeper')];
        }

        if ($city->has_healer && ! in_array('healer', $hiddenNpc, true)) {
            $rows[] = [self::cb(self::tavernNpcLabel('healer', $dailyNpc), 'city:healer')];
        }

        if ($city->has_blacksmith && ! in_array('blacksmith', $hiddenNpc, true)) {
            $rows[] = [self::cb(self::tavernNpcLabel('blacksmith', $dailyNpc), 'city:blacksmith')];
        }

        if ($city->has_buyer && ! in_array('buyer', $hiddenNpc, true)) {
            $rows[] = [self::cb(self::tavernNpcLabel('buyer', $dailyNpc), 'city:buyer')];
        }

        if ($city->has_overseer && ! in_array('overseer', $hiddenNpc, true)) {
            $rows[] = [self::cb(self::tavernNpcLabel('overseer', $dailyNpc), 'city:overseer')];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $leadingRows
     * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}  $markup
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function withLeadingRows(array $leadingRows, array $markup): array
    {
        if ($leadingRows === []) {
            return $markup;
        }

        $rows = [];

        foreach ($leadingRows as $leading) {
            $rows[] = [self::cb($leading['text'], $leading['callback_data'])];
        }

        foreach ($markup['inline_keyboard'] as $row) {
            $rows[] = $row;
        }

        return self::inline($rows);
    }

    /**
     * @param  list<string>  $npcKeys  healer|blacksmith|buyer|overseer
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function tavernQuest(City $city, array $npcKeys): array
    {
        $rows = [];

        if (in_array('healer', $npcKeys, true) && $city->has_healer) {
            $rows[] = [self::cb(__('telegram.btn.healer'), 'city:healer')];
        }

        if (in_array('blacksmith', $npcKeys, true) && $city->has_blacksmith) {
            $rows[] = [self::cb(__('telegram.btn.blacksmith'), 'city:blacksmith')];
        }

        if (in_array('buyer', $npcKeys, true) && $city->has_buyer) {
            $rows[] = [self::cb(__('telegram.btn.buyer'), 'city:buyer')];
        }

        if (in_array('overseer', $npcKeys, true) && $city->has_overseer) {
            $rows[] = [self::cb(__('telegram.btn.overseer'), 'city:overseer')];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return list<array{text: string, callback_data: string, style?: string}>
     */
    private static function dailyBackRow(bool $hasDailyReady, string $dailyListCallback, string $backCallback): array
    {
        if (! $hasDailyReady) {
            return [self::cbDanger(__('telegram.btn.back'), $backCallback)];
        }

        return [
            self::cbDanger(__('telegram.btn.back'), $backCallback),
            self::cbSuccess(__('telegram.daily.btn_contracts'), $dailyListCallback),
        ];
    }

    /**
     * @param  list<string>  $dailyNpc
     */
    private static function tavernNpcLabel(string $npcKey, array $dailyNpc): string
    {
        $label = __('telegram.btn.' . $npcKey);

        if (! in_array($npcKey, $dailyNpc, true)) {
            return $label;
        }

        return __('telegram.daily.npc_mark', ['name' => $label]);
    }
}
