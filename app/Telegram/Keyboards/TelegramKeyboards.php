<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\StatKeyEnum;
use App\Models\Character;
use App\Models\City;
use App\Models\Fight;
use App\Services\Shop\ShopCatalog;
use App\Support\Equipment\EquipmentDef;
use RuntimeException;

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
    public static function fightPick(array $enemies, string $filter, int $page): array
    {
        $items = [];

        foreach ($enemies as $enemy) {
            $items[] = [
                'text' => $enemy['text'],
                'callback_data' => 'fight:start:' . $enemy['catalog_id'],
            ];
        }

        return PaginatedListKeyboard::markup(
            $items,
            [],
            $filter,
            $page,
            'city:forest',
            'city:gates',
            null,
        );
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
            [self::cbDanger(__('menu.back'), 'menu:home')],
        ]);
    }

    /**
     * @param  list<list<array{text: string, callback_data: string, style?: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function custom(array $rows): array
    {
        return self::keyboardRows($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bagDiscardConfirm(int $bagItemId): array
    {
        return self::inline([
            [self::cb(__('menu.discard_confirm_yes'), 'bag:discard_yes:' . $bagItemId)],
            [self::cb(__('menu.bag'), 'menu:bag')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bagGemCard(int $bagItemId): array
    {
        return self::inline([
            [self::cb(__('menu.discard_item'), 'bag:discard:' . $bagItemId)],
            [self::cb(__('menu.to_smith'), 'smith:gems')],
            [self::cb(__('menu.bag'), 'menu:bag')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bagPotionCard(int $bagItemId): array
    {
        return self::inline([
            [self::cb(__('menu.discard_item'), 'bag:discard:' . $bagItemId)],
            [self::cb(__('menu.bag'), 'menu:bag')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function invDiscardConfirm(int $rowId): array
    {
        return self::inline([
            [self::cb(__('menu.discard_confirm_yes'), 'inv:discard_yes:' . $rowId)],
            [self::cb(__('menu.backpack'), 'inv:card:' . $rowId)],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function smithBack(): array
    {
        return self::inline([
            [self::cb(__('menu.smith'), 'menu:smith')],
            [self::cbDanger(__('menu.back'), 'menu:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function smithGemsNav(): array
    {
        return self::inline([
            [self::cb(__('smith.gems_btn'), 'smith:gems')],
            [self::cb(__('menu.smith'), 'menu:smith')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function smithOnly(): array
    {
        return self::inline([
            [self::cb(__('smith.gems_btn'), 'smith:gems')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function smithVipNav(): array
    {
        return self::inline([
            [self::cb(__('smith.vip_btn'), 'smith:vip')],
            [self::cb(__('menu.smith'), 'menu:smith')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null
     */
    public static function fightEndMarkup(FightEndUiEnum $ui, Character $player): ?array
    {
        return match ($ui) {
            FightEndUiEnum::None => null,
            FightEndUiEnum::MainMenu => self::mainMenu(),
            FightEndUiEnum::BackToCity => CityKeyboard::backToCity(),
            FightEndUiEnum::StatsQuest => self::statsQuest($player),
            FightEndUiEnum::Intro => self::intro(),
        };
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

        if ($city->has_blacksmith) {
            foreach ($shop->noviceWeapons() as $weapon) {
                $rows[] = [self::weaponButton($weapon, 'ob:buy:' . $weapon->itemId)];
            }
        }

        if ($city->has_buyer) {
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
    public static function fightInlineForStep(Fight $fight): array
    {
        if ($fight->step === FightStepEnum::STANCE) {
            if ($fight->tutorial) {
                return self::stance();
            }

            return self::stanceWithPotions();
        }

        if ($fight->step === FightStepEnum::ATTACK || $fight->step === FightStepEnum::ATTACK_SECOND) {
            return self::attackWithoutPotion();
        }

        if ($fight->step === FightStepEnum::DEFEND_SECOND) {
            if (! $fight->player_defend instanceof ZoneEnum) {
                throw new RuntimeException('Fight defend zone missing for second block.');
            }

            return self::defendExcluding($fight->player_defend);
        }

        return self::defend();
    }

    public static function fightMarkupKind(Fight $fight): string
    {
        if ($fight->step === FightStepEnum::STANCE) {
            if ($fight->tutorial) {
                return 'stance';
            }

            return 'stance_potions';
        }

        if ($fight->step === FightStepEnum::ATTACK || $fight->step === FightStepEnum::ATTACK_SECOND) {
            return 'zones_atk';
        }

        if ($fight->step === FightStepEnum::DEFEND_SECOND) {
            if (! $fight->player_defend instanceof ZoneEnum) {
                throw new RuntimeException('Fight defend zone missing for second block.');
            }

            return 'zones_excl_' . $fight->player_defend->value;
        }

        return 'zones_def';
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
    public static function stanceWithPotions(): array
    {
        return self::inline([
            [
                self::cb(__('combat.btn_attack'), 'fight:stance:ATTACK'),
                self::cb(__('combat.btn_defend'), 'fight:stance:DEFEND'),
            ],
            [
                self::cb(__('combat.btn_potion_short'), 'fight:atk:POTION'),
                self::cb(__('combat.btn_stamina_potion_short'), 'fight:atk:STAMINA_POTION'),
            ],
            [
                self::cbDanger(__('combat.btn_flee'), 'fight:flee'),
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
            [self::cbDanger(__('menu.back'), 'menu:stats')],
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
        $rows[] = [self::cbDanger(__('menu.back'), 'menu:home')];

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
            [self::cbDanger(__('menu.back'), 'menu:home')],
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
