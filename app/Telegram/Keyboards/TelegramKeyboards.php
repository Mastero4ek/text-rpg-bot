<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\StatKeyEnum;
use App\Models\Fight;
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
    public static function fightReportLog(): array
    {
        return self::inline([
            [self::cbSuccess(__('combat.btn_log'), 'fight:report:log')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function fightReportSummary(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'fight:report:summary')],
        ]);
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
    public static function fightEndMarkup(FightEndUiEnum $ui): ?array
    {
        return match ($ui) {
            FightEndUiEnum::None => null,
            FightEndUiEnum::MainMenu => self::mainMenu(),
            FightEndUiEnum::BackToCity => CityKeyboard::backToCity(),
            FightEndUiEnum::OverseerStats => self::overseerTrainStats(),
            FightEndUiEnum::Intro => self::overseerIntro(),
            FightEndUiEnum::BlacksmithMeet => self::blacksmithMeet(),
            FightEndUiEnum::BlacksmithComplete => self::blacksmithComplete(),
            FightEndUiEnum::BruiserArrive => self::bruiserArrive(),
            FightEndUiEnum::BruiserTalk => self::bruiserTalk(),
            FightEndUiEnum::BruiserComplete => self::bruiserComplete(),
            FightEndUiEnum::BruiserKnucklePending => self::bruiserKnucklePending(),
            FightEndUiEnum::BruiserKnockout => self::bruiserKnockout(),
            FightEndUiEnum::HealerDebt => self::healerDebt(),
            FightEndUiEnum::HealerRatLoot => self::healerRatLoot(),
            FightEndUiEnum::HealerDebtClosed => self::healerDebtClosed(),
        };
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserArrive(): array
    {
        return self::inline([
            [
                self::cb(__('telegram.quest.bruiser.btn.talk'), 'quest:bruiser:talk'),
                self::cb(__('telegram.quest.bruiser.btn.attack'), 'quest:bruiser:fight'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserTalk(): array
    {
        return self::inline([
            [
                self::cb(__('telegram.quest.bruiser.btn.threat'), 'quest:bruiser:threat'),
                self::cb(__('telegram.quest.bruiser.btn.bluff'), 'quest:bruiser:bluff'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserThreat(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.quest.bruiser.btn.to_fight'), 'quest:bruiser:fight')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserComplete(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.bruiser.btn.take_purse'), 'city:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserKnucklePending(): array
    {
        return self::inline([
            [
                self::cb(__('telegram.quest.bruiser.btn.take_purse'), 'city:home'),
                self::cbSuccess(__('telegram.quest.bruiser.btn.take_knuckle'), 'quest:bruiser:take_knuckle'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bruiserKnockout(): array
    {
        return self::inline([
            [self::cb(__('telegram.quest.bruiser.btn.wake'), 'quest:healer:wake')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerDebt(): array
    {
        return self::inline([
            [
                self::cb(__('telegram.quest.healer.btn.debt_pay'), 'quest:healer:debt_pay'),
                self::cb(__('telegram.quest.healer.btn.debt_work'), 'quest:healer:debt_work'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerDebtClosed(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.healer.btn.debt_done'), 'city:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerDebtWork(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.quest.healer.btn.debt_chicken'), 'quest:healer:debt'),
                self::cbSuccess(__('telegram.quest.healer.btn.debt_cellar'), 'quest:healer:rat'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerRatLoot(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.healer.btn.take_tail'), 'quest:healer:take_tail')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithComplete(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.blacksmith.btn.to_city'), 'city:gates')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmithMeet(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.quest.blacksmith.btn.refuse'), 'city:gates'),
                self::cbSuccess(__('telegram.quest.blacksmith.btn.fight'), 'quest:blacksmith:fight'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerMeet(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.quest.buyer.btn.refuse'), 'city:home'),
                self::cbSuccess(__('telegram.quest.buyer.btn.accept'), 'quest:buyer:accept'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function buyerTurnIn(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.buyer.btn.take_share'), 'quest:buyer:turnin')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerComplete(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.healer.btn.to_city'), 'city:gates')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function healerMeet(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.healer.btn.take'), 'quest:healer:take')],
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
    public static function overseerComplete(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.overseer.btn.to_city'), 'city:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerIntro(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.quest.overseer.btn.ready'), 'quest:overseer:ready')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerMeet(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.quest.overseer.btn.skip'), 'quest:overseer:skip'),
                self::cbSuccess(__('telegram.quest.overseer.btn.accept'), 'quest:overseer:accept'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerTrainOffer(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.not_now'), 'city:tavern'),
                self::cbSuccess(__('telegram.quest.overseer.btn.train'), 'quest:overseer:train'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerTrainStats(): array
    {
        return self::inline([
            [
                self::cb(__('profile.btn_str'), 'quest:overseer:stat:' . StatKeyEnum::STRENGTH->value),
                self::cb(__('profile.btn_agi'), 'quest:overseer:stat:' . StatKeyEnum::AGILITY->value),
            ],
            [
                self::cb(__('profile.btn_inst'), 'quest:overseer:stat:' . StatKeyEnum::INSTINCT->value),
                self::cb(__('profile.btn_vit'), 'quest:overseer:stat:' . StatKeyEnum::VITALITY->value),
            ],
        ]);
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
            $markup = self::attackWithoutPotion();
        } elseif ($fight->step === FightStepEnum::DEFEND_SECOND) {
            if (! $fight->player_defend instanceof ZoneEnum) {
                throw new RuntimeException('Fight defend zone missing for second block.');
            }

            $markup = self::defendExcluding($fight->player_defend);
        } else {
            $markup = self::defend();
        }

        if ($fight->tutorial) {
            return $markup;
        }

        $rows = $markup['inline_keyboard'];
        $rows[] = [self::cbDanger(__('combat.btn_flee'), 'fight:flee')];

        return self::inline($rows);
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
}
