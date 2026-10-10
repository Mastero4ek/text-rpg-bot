<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Enemy\EnemyKindEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Character;
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
            [self::cbDanger(__('telegram.btn.back'), 'city:arena')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function boardList(array $items, string $filter, int $page): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            self::boardFilters(),
            $filter,
            $page,
            'city:board',
            'city:home',
            null,
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
            'city:arena',
            null,
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
            'city:arena',
            null,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmith(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.gear'), 'city:blacksmith:gear')],
            [self::cb(__('telegram.btn.repair'), 'city:blacksmith:repair')],
            [self::cbDanger(__('telegram.btn.back'), 'city:tavern')],
        ]);
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithGear(array $items, string $filter, int $page): array
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
        );
    }

    /**
     * @param  list<array{text: string, row_id: int}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithRepair(array $items, string $filter, int $page, ?string $repairAllBtn): array
    {
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
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerOffer(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.btn.heal'), 'city:healer:heal')],
            [self::cb(__('telegram.btn.potions'), 'city:healer:potions')],
            [self::cbDanger(__('telegram.btn.back'), 'city:tavern')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerOffer(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.chest'), 'city:buyer:chest')],
            [self::cb(__('telegram.btn.sell'), 'city:buyer:sell')],
            [self::cbDanger(__('telegram.btn.back'), 'city:tavern')],
        ]);
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerChest(array $items, string $filter, int $page): array
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
        );
    }

    /**
     * @param  list<array{text: string, source: string, row_id: int}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerSell(array $items, string $filter, int $page): array
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
    public static function healerPotions(array $potions, string $filter, int $page): array
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
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function firstHome(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.pass'), 'ob:pass'),
                self::cbSuccess(__('telegram.btn.hall'), 'ob:hall'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gates(City $city): array
    {
        $rows = [];

        if ($city->has_portal) {
            $rows[] = [self::cb(__('telegram.btn.portal'), 'city:portal')];
        }

        if ($city->has_forest) {
            $rows[] = [self::cb(__('telegram.btn.forest'), 'city:forest')];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerOffer(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.not_now'), 'ob:not_now'),
                self::cbSuccess(__('telegram.btn.hall'), 'ob:hall'),
            ],
        ]);
    }

    /**
     * @param  iterable<int, City>  $targets
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function portalTargets(iterable $targets, string $filter, int $page): array
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
    public static function services(City $city): array
    {
        $rows = [
            [self::cb(__('telegram.btn.gates'), 'city:gates')],
            [self::cb(__('telegram.btn.tavern'), 'city:tavern')],
        ];

        if ($city->has_quest_board) {
            $rows[] = [self::cb(__('telegram.btn.board'), 'city:board')];
        }

        $rows[] = [self::cb(__('telegram.btn.arena'), 'city:arena')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function tavern(City $city, Character $character): array
    {
        $rows = [];

        if ($city->has_healer) {
            $rows[] = [self::cb(__('telegram.btn.healer'), 'city:healer')];
        }

        if ($city->has_blacksmith) {
            $rows[] = [self::cb(__('telegram.btn.blacksmith'), 'city:blacksmith')];
        }

        if ($city->has_buyer) {
            $rows[] = [self::cb(__('telegram.btn.buyer'), 'city:buyer')];
        }

        if ($character->onboarding_skipped) {
            $rows[] = [self::cb(__('telegram.btn.overseer'), 'city:overseer')];
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }
}
