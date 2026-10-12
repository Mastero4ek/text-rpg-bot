<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Enemy\EnemyKindEnum;
use App\Enums\Equipment\TypeEnum;
use App\Enums\NpcEnum;
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
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function fightsList(array $items, int $page): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            [],
            'all',
            $page,
            'city:fights',
            'city:lists',
            null,
            count($items),
        );
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
                'callback_data' => 'city:blacksmith:buy:' . $item['catalog_id'],
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
                'callback_data' => 'city:blacksmith:repair:' . $item['row_id'],
            ];
        }

        $extra = null;

        if ($repairAllBtn !== null) {
            $extra = [
                'text' => $repairAllBtn,
                'callback_data' => 'city:blacksmith:repair_all',
                'style' => 'success',
            ];
        }

        return PaginatedListKeyboard::markup(
            $rows,
            self::gearTypeFilters(),
            $filter,
            $page,
            'city:blacksmith:repair',
            'city:blacksmith',
            $extra,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerOffer(): array
    {
        return self::healerOfferWithDaily(false);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerOfferWithDaily(bool $hasDailyReady): array
    {
        $rows = [];

        if ($hasDailyReady) {
            $rows[] = [self::cbSuccess(__('telegram.daily.btn_contracts'), 'daily:healer:list')];
        }

        $rows[] = [self::cbSuccess(__('telegram.btn.heal'), 'city:healer:heal')];
        $rows[] = [self::cb(__('telegram.btn.potions'), 'city:healer:potions')];
        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:tavern')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmith(): array
    {
        return self::blacksmithWithDaily(false);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithWithDaily(bool $hasDailyReady): array
    {
        $rows = [];

        if ($hasDailyReady) {
            $rows[] = [self::cbSuccess(__('telegram.daily.btn_contracts'), 'daily:blacksmith:list')];
        }

        $rows[] = [self::cb(__('telegram.btn.gear'), 'city:blacksmith:gear')];
        $rows[] = [self::cb(__('telegram.btn.repair'), 'city:blacksmith:repair')];
        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:tavern')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerOffer(): array
    {
        return self::buyerOfferWithDaily(false);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerOfferWithDaily(bool $hasDailyReady): array
    {
        $rows = [];

        if ($hasDailyReady) {
            $rows[] = [self::cbSuccess(__('telegram.daily.btn_contracts'), 'daily:buyer:list')];
        }

        $rows[] = [self::cb(__('telegram.btn.chest'), 'city:buyer:chest')];
        $rows[] = [self::cb(__('telegram.btn.sell'), 'city:buyer:sell')];
        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:tavern')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function npcOfferWithDaily(NpcEnum $issuer, bool $hasDailyReady, int $statPoints): array
    {
        return match ($issuer) {
            NpcEnum::Buyer => self::buyerOfferWithDaily($hasDailyReady),
            NpcEnum::Healer => self::healerOfferWithDaily($hasDailyReady),
            NpcEnum::Blacksmith => self::blacksmithWithDaily($hasDailyReady),
            NpcEnum::Overseer => self::overseerWithDaily($hasDailyReady, $statPoints),
            NpcEnum::Bruiser => self::backToTavern(),
        };
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
    public static function dailyNpcTurnIn(NpcEnum $issuer, int $contractId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.daily.btn_later'), 'city:' . $issuer->value),
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
        return self::dailyNpcTurnIn(NpcEnum::Buyer, $contractId);
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
                'callback_data' => 'city:buyer:buy:' . $item['catalog_id'],
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
     * @param  list<array{text: string, source: string, row_id: int}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerSell(array $items, string $filter, int $page, int $totalCount): array
    {
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'text' => $item['text'],
                'callback_data' => 'city:buyer:sell:' . $item['source'] . ':' . $item['row_id'],
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
    public static function buyerSellConfirm(string $source, int $rowId): array
    {
        return self::inline([
            [
                self::cbDanger(__('shop.sell_no'), 'city:buyer:sell'),
                self::cbSuccess(__('shop.sell_yes'), 'city:buyer:sell_yes:' . $source . ':' . $rowId),
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
                'callback_data' => 'city:healer:potion:' . $potion['catalog_id'],
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
        return self::overseerWithDaily(false, $statPoints);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerWithDaily(bool $hasDailyReady, int $statPoints): array
    {
        $rows = [];

        if ($hasDailyReady) {
            $rows[] = [self::cbSuccess(__('telegram.daily.btn_contracts'), 'daily:overseer:list')];
        }

        $rows[] = [self::cbSuccess(__('telegram.btn.reset_stats'), 'city:overseer:reset')];

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

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:tavern')];

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
            if ($target->portal_cost_silver <= 0) {
                $label = __('telegram.location.portal_row_free', [
                    'name' => $target->name,
                ]);
            } else {
                $label = __('telegram.location.portal_row', [
                    'name' => $target->name,
                    'cost' => $target->portal_cost_silver,
                ]);
            }

            $rows[] = [
                'text' => $label,
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
            ['id' => 'wpn', 'label' => __('telegram.btn.filter_weapon')],
            ['id' => 'arm', 'label' => __('telegram.btn.filter_armor')],
            ['id' => 'jwl', 'label' => __('telegram.btn.filter_jewelry')],
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
        if ($filter === 'wpn') {
            return TypeEnum::WEAPON;
        }

        if ($filter === 'arm') {
            return TypeEnum::ARMOR;
        }

        if ($filter === 'jwl') {
            return TypeEnum::JEWELRY;
        }

        return null;
    }

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
     * @param  list<string>  $dailyNpc  healer|blacksmith|buyer|overseer with turn-inable daily
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function tavern(City $city, array $dailyNpc): array
    {
        $rows = [];

        if ($city->has_healer) {
            $rows[] = [self::cb(self::tavernNpcLabel('healer', $dailyNpc), 'city:healer')];
        }

        if ($city->has_blacksmith) {
            $rows[] = [self::cb(self::tavernNpcLabel('blacksmith', $dailyNpc), 'city:blacksmith')];
        }

        if ($city->has_buyer) {
            $rows[] = [self::cb(self::tavernNpcLabel('buyer', $dailyNpc), 'city:buyer')];
        }

        if ($city->has_overseer) {
            $rows[] = [self::cb(self::tavernNpcLabel('overseer', $dailyNpc), 'city:overseer')];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

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
