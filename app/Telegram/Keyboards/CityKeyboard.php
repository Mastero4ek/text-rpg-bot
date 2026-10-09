<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

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

        $rows[] = [self::cb(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToArena(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.back'), 'city:arena')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToCity(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.back'), 'menu:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToGates(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.back'), 'city:gates')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToTavern(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.back'), 'city:tavern')],
        ]);
    }

    /**
     * @param  list<array{text: string, catalog_id: string}>  $enemies
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function trainingPick(array $enemies): array
    {
        $rows = [];

        foreach ($enemies as $enemy) {
            $rows[] = [self::cb($enemy['text'], 'city:training:start:' . $enemy['catalog_id'])];
        }

        $rows[] = [self::cb(__('telegram.btn.back'), 'city:arena')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmith(): array
    {
        return self::inline([
            [self::cb(__('telegram.btn.gear'), 'city:blacksmith:gear')],
            [self::cb(__('telegram.btn.repair'), 'city:blacksmith:repair')],
            [self::cb(__('telegram.btn.back'), 'city:tavern')],
        ]);
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

        $rows[] = [self::cb(__('telegram.btn.back'), 'city:home')];

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
    public static function portalTargets(iterable $targets): array
    {
        $rows = [];

        foreach ($targets as $target) {
            $rows[] = [self::cb(__('telegram.location.portal_row', [
                'name' => $target->name,
                'cost' => $target->portal_cost_silver,
            ]), 'portal:' . $target->id)];
        }

        $rows[] = [self::cb(__('telegram.btn.back'), 'city:gates')];

        return self::inline($rows);
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

        $rows[] = [self::cb(__('telegram.btn.back'), 'city:home')];

        return self::inline($rows);
    }
}
