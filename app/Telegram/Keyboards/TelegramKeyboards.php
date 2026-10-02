<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\FightPlayerAttackEnum;
use App\Enums\StatKeyEnum;
use App\Enums\ZoneEnum;
use App\Models\Character;
use App\Services\Shop\ShopCatalog;
use App\Support\Game\ItemDef;

final class TelegramKeyboards
{
    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function mainMenu(): array
    {
        return self::inline([
            [self::cb(__('menu.profile'), 'menu:profile')],
            [self::cb(__('menu.inventory'), 'menu:inv')],
            [self::cb(__('menu.shop'), 'menu:shop')],
            [self::cb(__('menu.fight'), 'menu:fight')],
            [self::cb(__('menu.stats'), 'menu:stats')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function statsUpgrade(): array
    {
        return self::inline([
            [
                self::cb(__('profile.btn_str'), 'stat:' . StatKeyEnum::STRENGTH->value),
                self::cb(__('profile.btn_agi'), 'stat:' . StatKeyEnum::AGILITY->value),
            ],
            [
                self::cb(__('profile.btn_inst'), 'stat:' . StatKeyEnum::INSTINCT->value),
                self::cb(__('profile.btn_vit'), 'stat:' . StatKeyEnum::VITALITY->value),
            ],
            [self::cb(__('menu.back'), 'menu:home')],
        ]);
    }

    /**
     * @param  list<string>  $cities
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function city(array $cities): array
    {
        $rows = [];

        foreach ($cities as $city) {
            $rows[] = [self::cb($city, 'ob:city:' . $city)];
        }

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function intro(): array
    {
        return self::inline([
            [self::cb(__('onboarding.btn_ready'), 'ob:intro_fight')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function statsQuest(Character $character): array
    {
        if ($character->stat_points <= 0) {
            return self::inline([
                [self::cb(__('onboarding.btn_submit_stats'), 'ob:stats_done')],
            ]);
        }

        return self::inline([
            [
                self::cb(__('profile.btn_str'), 'ob:stat:' . StatKeyEnum::STRENGTH->value),
                self::cb(__('profile.btn_agi'), 'ob:stat:' . StatKeyEnum::AGILITY->value),
            ],
            [
                self::cb(__('profile.btn_inst'), 'ob:stat:' . StatKeyEnum::INSTINCT->value),
                self::cb(__('profile.btn_vit'), 'ob:stat:' . StatKeyEnum::VITALITY->value),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function equipMail(): array
    {
        return self::inline([
            [self::cb(__('onboarding.btn_equip_mail'), 'ob:equip_mail')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function noviceShop(ShopCatalog $shop): array
    {
        $rows = [];

        foreach ($shop->noviceWeapons() as $weapon) {
            $rows[] = [self::weaponButton($weapon, 'ob:buy:' . $weapon->itemId)];
        }

        $rows[] = [self::cb(__('shop.potion_btn', ['price' => $shop->potionPrice()]), 'ob:novice_potion')];
        $rows[] = [self::cb(__('onboarding.btn_claim_club'), 'ob:claim_club')];

        return self::inline($rows);
    }

    /**
     * @param  list<ItemDef>  $weapons
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function fullShop(array $weapons, int $potionPrice): array
    {
        $rows = [];

        foreach ($weapons as $weapon) {
            $rows[] = [self::weaponButton($weapon, 'shop:w:' . $weapon->itemId)];
        }

        $rows[] = [self::cb(__('shop.potion_btn', ['price' => $potionPrice]), 'shop:potion')];
        $rows[] = [self::cb(__('menu.back'), 'menu:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function fightPick(int $playerLevel): array
    {
        return self::inline([
            [self::cb(__('combat.btn_soldier'), 'fight:start:soldier')],
            [self::cb(__('combat.btn_wanderer', ['level' => $playerLevel]), 'fight:start:mob')],
            [self::cb(__('menu.back'), 'menu:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function stance(): array
    {
        return self::inline([
            [
                self::cb(__('combat.btn_attack'), 'fight:stance:ATTACK'),
                self::cb(__('combat.btn_defend'), 'fight:stance:DEFEND'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function attackWithPotion(): array
    {
        return self::attackRows(true);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function attackWithoutPotion(): array
    {
        return self::attackRows(false);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function defend(): array
    {
        return self::inline([
            [
                self::cb(__('combat.zone_label.' . ZoneEnum::HEAD->value), 'fight:def:' . ZoneEnum::HEAD->value),
                self::cb(__('combat.zone_label.' . ZoneEnum::CHEST->value), 'fight:def:' . ZoneEnum::CHEST->value),
            ],
            [
                self::cb(__('combat.zone_label.' . ZoneEnum::BELLY->value), 'fight:def:' . ZoneEnum::BELLY->value),
                self::cb(__('combat.zone_label.' . ZoneEnum::LEGS->value), 'fight:def:' . ZoneEnum::LEGS->value),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private static function attackRows(bool $canPotion): array
    {
        $rows = [
            [
                self::cb(__('combat.zone_label.' . ZoneEnum::HEAD->value), 'fight:atk:' . ZoneEnum::HEAD->value),
                self::cb(__('combat.zone_label.' . ZoneEnum::CHEST->value), 'fight:atk:' . ZoneEnum::CHEST->value),
            ],
            [
                self::cb(__('combat.zone_label.' . ZoneEnum::BELLY->value), 'fight:atk:' . ZoneEnum::BELLY->value),
                self::cb(__('combat.zone_label.' . ZoneEnum::LEGS->value), 'fight:atk:' . ZoneEnum::LEGS->value),
            ],
        ];

        if ($canPotion) {
            $rows[] = [self::cb(__('combat.btn_potion'), 'fight:atk:' . FightPlayerAttackEnum::POTION->value)];
        }

        return self::inline($rows);
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private static function cb(string $text, string $data): array
    {
        return [
            'text' => $text,
            'callback_data' => $data,
        ];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    private static function weaponButton(ItemDef $weapon, string $data): array
    {
        return self::cb(
            __('shop.weapon_btn', [
                'name' => $weapon->itemName,
                'price' => $weapon->price,
            ]),
            $data,
        );
    }
}
