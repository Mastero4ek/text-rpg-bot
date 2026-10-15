<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Enums\StatKeyEnum;
use App\Models\Backpack\BackpackItem;
use App\Models\Fight;
use App\Support\Equipment\EquippedLoadout;
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
            count($items),
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
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function journalList(array $items, string $activeFilter, int $page, int $totalCount): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            [
                ['id' => 'all', 'label' => __('telegram.btn.filter_all')],
                ['id' => 'hunt', 'label' => __('telegram.btn.filter_board_orders')],
                ['id' => 'delivery', 'label' => __('telegram.btn.filter_board_asks')],
            ],
            $activeFilter,
            $page,
            'journal',
            null,
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function journalCard(string $filter): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'journal:list:' . $filter . ':1')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function appealList(array $items, int $page, int $totalCount): array
    {
        $markup = PaginatedListKeyboard::markup(
            $items,
            [],
            'all',
            $page,
            'appeal',
            null,
            null,
            $totalCount,
        );

        $markup['inline_keyboard'][] = [
            self::cbSuccess(__('telegram.appeal.btn.create'), 'appeal:new'),
        ];

        return $markup;
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function appealCard(int $ticketId, bool $canCancel): array
    {
        if ($canCancel) {
            return self::inline([
                [
                    self::cbDanger(__('telegram.btn.back'), 'appeal:list:all:1'),
                    self::cb(__('telegram.appeal.btn.cancel'), 'appeal:cancel:ask:' . $ticketId),
                ],
            ]);
        }

        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'appeal:list:all:1')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function appealCancelConfirm(int $ticketId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'appeal:card:' . $ticketId),
                self::cbSuccess(__('telegram.btn.yes'), 'appeal:cancel:yes:' . $ticketId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function appealCompose(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'appeal:list:all:1')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryHome(): array
    {
        return self::inline([
            [
                self::cb(__('telegram.library.btn.locations'), 'lib:loc'),
                self::cb(__('telegram.library.btn.gear'), 'lib:gear'),
            ],
            [
                self::cb(__('telegram.library.btn.potions'), 'lib:pot'),
                self::cb(__('telegram.library.btn.gems'), 'lib:gem'),
            ],
            [
                self::cb(__('telegram.library.btn.stats'), 'lib:stat'),
                self::cb(__('telegram.library.btn.combat'), 'lib:combat'),
            ],
            [
                self::cb(__('telegram.library.btn.enemies'), 'lib:enemy'),
                self::cb(__('telegram.library.btn.minigames'), 'lib:game'),
            ],
            [
                self::cb(__('telegram.library.btn.npcs'), 'lib:npc'),
                self::cb(__('telegram.library.btn.contracts'), 'lib:contract'),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryGear(): array
    {
        return self::inline([
            [self::cb(__('telegram.library.btn.gear_wear'), 'lib:gear:wear')],
            [self::cb(__('telegram.library.btn.gear_socket'), 'lib:gear:socket')],
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryGearCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:gear')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryPotionCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryGemCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryStatsCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryCombatCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryContractCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryLocationCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:loc')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryNpcs(array $items): array
    {
        $rows = [];
        $row = [];

        foreach ($items as $item) {
            $row[] = self::cb($item['text'], $item['callback_data']);

            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }

        if (! empty($row)) {
            $rows[] = $row;
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'lib:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryNpcCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:npc')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryEnemies(array $items): array
    {
        return self::libraryNpcs($items);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryEnemyCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:enemy')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryMinigames(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.lucky_barrel'), 'lib:game:lucky_barrel')],
            [self::cbDanger(__('telegram.btn.back'), 'lib:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryMinigameCard(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.btn.back'), 'lib:game')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function libraryLocations(array $items): array
    {
        $fullWidth = [
            'lib:loc:gates',
            'lib:loc:board',
            'lib:loc:training',
        ];
        $rows = [];
        $row = [];

        foreach ($items as $item) {
            if (in_array($item['callback_data'], $fullWidth, true)) {
                if (! empty($row)) {
                    $rows[] = $row;
                    $row = [];
                }

                $rows[] = [self::cb($item['text'], $item['callback_data'])];

                continue;
            }

            $row[] = self::cb($item['text'], $item['callback_data']);

            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }

        if (! empty($row)) {
            $rows[] = $row;
        }

        $rows[] = [self::cbDanger(__('telegram.btn.back'), 'lib:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function profile(): array
    {
        return self::inline([
            [self::cb(__('profile.btn_gear'), 'menu:gear')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearDoll(EquippedLoadout $loadout): array
    {
        return self::inline([
            [self::gearSlotButton($loadout, SlotEnum::HELMET)],
            [self::gearSlotButton($loadout, SlotEnum::AMULET)],
            [self::gearSlotButton($loadout, SlotEnum::ARMOR)],
            [
                self::gearSlotButton($loadout, SlotEnum::LEFT_HAND),
                self::gearSlotButton($loadout, SlotEnum::RIGHT_HAND),
            ],
            [
                self::gearSlotButton($loadout, SlotEnum::GLOVES),
                self::gearSlotButton($loadout, SlotEnum::SHIELD),
            ],
            [
                self::gearSlotButton($loadout, SlotEnum::RING_1),
                self::gearSlotButton($loadout, SlotEnum::RING_2),
            ],
            [self::gearSlotButton($loadout, SlotEnum::PANTS)],
            [self::gearSlotButton($loadout, SlotEnum::BOOTS)],
            [self::cbDanger(__('menu.back'), 'menu:profile')],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $gemButtons
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearEquippedCard(int $rowId, array $gemButtons): array
    {
        $rows = [];

        foreach ($gemButtons as $gemButton) {
            $rows[] = [self::cb($gemButton['text'], $gemButton['callback_data'])];
        }

        $rows[] = [
            self::cbDanger(__('menu.back'), 'menu:gear'),
            self::cb(__('menu.unequip'), 'inv:uneq:ask:' . $rowId),
        ];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearEquipConfirm(int $rowId, ?SlotEnum $slot, string $cancelCallback): array
    {
        if ($slot instanceof SlotEnum) {
            $yes = 'inv:eq:yes:' . $rowId . ':' . $slot->value;
        } else {
            $yes = 'inv:eq:yes:' . $rowId;
        }

        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), $cancelCallback),
                self::cbSuccess(__('telegram.btn.yes'), $yes),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearUnequipConfirm(int $rowId, string $cancelCallback): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), $cancelCallback),
                self::cbSuccess(__('telegram.btn.yes'), 'inv:uneq:yes:' . $rowId),
            ],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearGemPickList(
        array $items,
        int $hostId,
        int $page,
        int $totalCount,
        string $backCallback,
        ?SlotEnum $pickSlot,
    ): array {
        $prefix = 'gear:gem:pick:' . $hostId;

        if ($pickSlot instanceof SlotEnum) {
            $prefix .= ':pick:' . $pickSlot->value;
        }

        return PaginatedListKeyboard::markup(
            $items,
            [],
            'all',
            $page,
            $prefix,
            $backCallback,
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearGemSocketConfirm(int $hostId, int $bagItemId, ?SlotEnum $pickSlot): array
    {
        $pickTail = '';

        if ($pickSlot instanceof SlotEnum) {
            $pickTail = ':pick:' . $pickSlot->value;
        }

        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'gear:gem:pick:' . $hostId . $pickTail),
                self::cbSuccess(
                    __('telegram.btn.yes'),
                    'gear:gem:socket:' . $hostId . ':' . $bagItemId . $pickTail,
                ),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearGemViewCard(string $backCallback): array
    {
        return self::inline([
            [self::cbDanger(__('menu.back'), $backCallback)],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backpackList(array $items, string $activeFilter, int $page, int $totalCount): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            [
                ['id' => 'WEAPON', 'label' => __('telegram.btn.filter_weapon')],
                ['id' => 'ARMOR', 'label' => __('telegram.btn.filter_armor')],
                ['id' => 'JEWELRY', 'label' => __('telegram.btn.filter_jewelry')],
            ],
            $activeFilter,
            $page,
            'inv',
            null,
            null,
            $totalCount,
        );
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bagList(array $items, string $activeFilter, int $page, int $totalCount): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            [
                ['id' => 'POTION', 'label' => __('telegram.btn.filter_potion')],
                ['id' => 'GEM', 'label' => __('telegram.btn.filter_gem')],
                ['id' => 'QUEST', 'label' => __('telegram.btn.filter_quest')],
            ],
            $activeFilter,
            $page,
            'bag',
            null,
            null,
            $totalCount,
        );
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $items
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearPickList(array $items, SlotEnum $slot, int $page, int $totalCount): array
    {
        return PaginatedListKeyboard::markup(
            $items,
            [],
            'all',
            $page,
            'gear:pick:' . $slot->value,
            'menu:gear',
            null,
            $totalCount,
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function invCard(int $rowId): array
    {
        return self::inline([
            [
                self::cbDanger(__('menu.back'), 'inv:list:WEAPON:1'),
                self::cb(__('menu.discard_item'), 'inv:discard:' . $rowId),
            ],
        ]);
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $gemButtons
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gearPickCard(int $rowId, SlotEnum $slot, array $gemButtons): array
    {
        $rows = [];

        foreach ($gemButtons as $gemButton) {
            $rows[] = [self::cb($gemButton['text'], $gemButton['callback_data'])];
        }

        $rows[] = [
            self::cbDanger(__('menu.back'), 'gear:pick:' . $slot->value),
            self::cbSuccess(__('menu.wear_item'), 'inv:eq:ask:' . $rowId . ':' . $slot->value),
        ];

        return self::inline($rows);
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
    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bagCard(int $bagItemId, string $filter): array
    {
        return self::inline([
            [
                self::cbDanger(__('menu.back'), 'bag:list:' . $filter . ':1'),
                self::cb(__('menu.discard_item'), 'bag:discard:' . $bagItemId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function bagDiscardConfirm(int $bagItemId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'bag:card:' . $bagItemId),
                self::cbSuccess(__('telegram.btn.yes'), 'bag:discard_yes:' . $bagItemId),
            ],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function invDiscardConfirm(int $rowId): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.btn.no'), 'inv:card:' . $rowId),
                self::cbSuccess(__('telegram.btn.yes'), 'inv:discard_yes:' . $rowId),
            ],
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
        if ($fight->step === FightStepEnum::WAIT) {
            return self::inline([]);
        }

        if ($fight->step === FightStepEnum::STANCE) {
            if ($fight->tutorial) {
                return self::stance();
            }

            if ($fight->kind === FightKindEnum::PVP) {
                return self::stanceWithPotionsNoFlee();
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

        if ($fight->tutorial || $fight->kind === FightKindEnum::PVP) {
            return $markup;
        }

        $rows = $markup['inline_keyboard'];
        $rows[] = [self::cbDanger(__('combat.btn_flee'), 'fight:flee')];

        return self::inline($rows);
    }

    public static function fightMarkupKind(Fight $fight): string
    {
        if ($fight->step === FightStepEnum::WAIT) {
            return 'wait';
        }

        if ($fight->step === FightStepEnum::STANCE) {
            if ($fight->tutorial) {
                return 'stance';
            }

            if ($fight->kind === FightKindEnum::PVP) {
                return 'stance_potions_pvp';
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
    public static function stanceWithPotionsNoFlee(): array
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
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function pvpCard(int $challengeId, bool $canAccept): array
    {
        $rows = [];

        if ($canAccept) {
            $rows[] = [
                self::cbDanger(__('telegram.btn.back'), 'pvp:list:all:1'),
                self::cbSuccess(__('telegram.btn.pvp_accept'), 'pvp:accept:yes:' . $challengeId),
            ];
        } else {
            $rows[] = [self::cbDanger(__('telegram.btn.back'), 'pvp:list:all:1')];
        }

        return self::inline($rows);
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
    private static function gearSlotButton(EquippedLoadout $loadout, SlotEnum $slot): array
    {
        $row = $loadout->row($slot);

        if ($row instanceof BackpackItem) {
            return self::cb(
                __('menu.gear_slot', [
                    'slot' => $slot->getLabel(),
                    'item' => $row->item_name,
                ]),
                'gear:view:' . $row->id,
            );
        }

        return self::cb(
            __('menu.gear_slot', [
                'slot' => $slot->getLabel(),
                'item' => __('menu.gear_empty'),
            ]),
            'gear:pick:' . $slot->value,
        );
    }
}
