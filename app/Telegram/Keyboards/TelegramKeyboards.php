<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\StatKeyEnum;
use App\Models\Character;
use App\Models\City;
use App\Services\Shop\ShopCatalog;
use App\Support\Equipment\EquipmentDef;

final class TelegramKeyboards
{
    use BuildsInlineKeyboard;

    /**
     * @param  list<PlayerAttackEnum>  $potionAttacks
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function attack(array $potionAttacks): array
    {
        return self::attackRows($potionAttacks);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function attackWithoutPotion(): array
    {
        return self::attackRows([]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function clearInline(): array
    {
        return self::inline([]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function defend(): array
    {
        return self::defendButtons(null);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function defendExcluding(ZoneEnum $excluded): array
    {
        return self::defendButtons($excluded);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function equipMail(): array
    {
        return self::inline([
            [self::cb(__('onboarding.btn_equip_mail'), 'ob:equip_mail')],
        ]);
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $enemies
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function fightPick(array $enemies): array
    {
        $rows = [];

        foreach ($enemies as $enemy) {
            $rows[] = [self::cb($enemy['text'], 'fight:start:' . $enemy['catalog_id'])];
        }

        $rows[] = [self::cb(__('menu.back'), 'menu:home')];

        return self::inline($rows);
    }

    /**
     * @param  list<EquipmentDef>  $weapons
     * @param  list<EquipmentDef>  $gear
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function fullShop(
        array $weapons,
        array $gear,
        int $healPotionPrice,
        int $staminaPotionPrice,
    ): array {
        $rows = [];

        foreach ($weapons as $weapon) {
            $rows[] = [self::weaponButton($weapon, 'shop:w:' . $weapon->itemId)];
        }

        foreach ($gear as $item) {
            $rows[] = [self::weaponButton($item, 'shop:g:' . $item->itemId)];
        }

        $rows[] = [self::cb(__('shop.potion_btn', ['price' => $healPotionPrice]), 'shop:potion')];
        $rows[] = [self::cb(__('shop.stamina_potion_btn', ['price' => $staminaPotionPrice]), 'shop:stamina_potion')];
        $rows[] = [self::cb(__('shop.sell_btn'), 'shop:sell')];
        $rows[] = [self::cb(__('menu.back'), 'menu:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function intro(): array
    {
        return self::inline([
            [self::cb(__('onboarding.btn_ready'), 'ob:intro_fight')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function inventoryHub(): array
    {
        return self::inline([
            [self::cb(__('menu.backpack'), 'menu:inv')],
            [self::cb(__('menu.bag'), 'menu:bag')],
            [self::cb(__('menu.gear'), 'menu:gear')],
            [self::cb(__('menu.back'), 'menu:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function mainMenu(): array
    {
        return CityKeyboard::backToCity();
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function noviceShop(ShopCatalog $shop, int $potionPrice, City $city): array
    {
        $rows = [];

        if ($city->has_shop) {
            foreach ($shop->noviceWeapons() as $weapon) {
                $rows[] = [self::weaponButton($weapon, 'ob:buy:' . $weapon->itemId)];
            }

            $rows[] = [self::cb(__('shop.potion_btn', ['price' => $potionPrice]), 'ob:novice_potion')];
        }

        $rows[] = [self::cb(__('onboarding.btn_claim_club'), 'ob:claim_club')];

        return self::inline($rows);
    }

    /**
     * @return array{remove_keyboard: true}
     */
    public static function removeReply(): array
    {
        return ['remove_keyboard' => true];
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
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
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
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
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function statsResetConfirm(): array
    {
        return self::inline([
            [self::cb(__('profile.btn_reset_confirm'), 'stat:reset_yes')],
            [self::cb(__('menu.back'), 'menu:stats')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function statsScreen(int $statPoints, int $resetGoldCost): array
    {
        $rows = [];

        if ($statPoints > 0) {
            $rows[] = [
                self::cb(__('profile.btn_str'), 'stat:' . StatKeyEnum::STRENGTH->value),
                self::cb(__('profile.btn_agi'), 'stat:' . StatKeyEnum::AGILITY->value),
            ];
            $rows[] = [
                self::cb(__('profile.btn_inst'), 'stat:' . StatKeyEnum::INSTINCT->value),
                self::cb(__('profile.btn_vit'), 'stat:' . StatKeyEnum::VITALITY->value),
            ];
        }

        $rows[] = [self::cb(__('profile.btn_reset', ['gold' => $resetGoldCost]), 'stat:reset')];
        $rows[] = [self::cb(__('menu.back'), 'menu:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
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
     * @param  list<PlayerAttackEnum>  $potionAttacks
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private static function attackRows(array $potionAttacks): array
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

        foreach ($potionAttacks as $attack) {
            if ($attack === PlayerAttackEnum::POTION) {
                $rows[] = [self::cb(__('combat.btn_potion'), 'fight:atk:' . $attack->value)];

                continue;
            }

            if ($attack === PlayerAttackEnum::STAMINA_POTION) {
                $rows[] = [self::cb(__('combat.btn_stamina_potion'), 'fight:atk:' . $attack->value)];
            }
        }

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    private static function defendButtons(?ZoneEnum $excluded): array
    {
        $zones = [
            ZoneEnum::HEAD,
            ZoneEnum::CHEST,
            ZoneEnum::BELLY,
            ZoneEnum::LEGS,
        ];
        $buttons = [];

        foreach ($zones as $zone) {
            if ($excluded instanceof ZoneEnum && $zone === $excluded) {
                continue;
            }

            $buttons[] = self::cb(
                __('combat.zone_label.' . $zone->value),
                'fight:def:' . $zone->value,
            );
        }

        $rows = [];
        $row = [];

        foreach ($buttons as $button) {
            $row[] = $button;

            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return self::inline($rows);
    }

    /**
     * @return array{text: string, callback_data: string, style?: string}
     */
    private static function weaponButton(EquipmentDef $weapon, string $data): array
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
