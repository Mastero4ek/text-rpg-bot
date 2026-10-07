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
            $rows[] = [self::cb(__('telegram.city.btn_training'), 'city:training')];
        }

        if ($city->has_fights_list) {
            $rows[] = [self::cb(__('telegram.city.btn_fights'), 'city:fights')];
        }

        $rows[] = [self::cb(__('telegram.city.btn_back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToCity(): array
    {
        return self::inline([
            [self::cb(__('telegram.city.btn_back'), 'menu:home')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function backToTavern(): array
    {
        return self::inline([
            [self::cb(__('telegram.city.btn_back'), 'city:tavern')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function blacksmith(): array
    {
        return self::inline([
            [self::cb(__('telegram.city.btn_gear'), 'city:blacksmith:gear')],
            [self::cb(__('telegram.city.btn_repair'), 'city:blacksmith:repair')],
            [self::cb(__('telegram.city.btn_back'), 'city:tavern')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function firstHome(City $city): array
    {
        $rows = [
            [
                self::cbDanger(__('telegram.city.btn_pass'), 'ob:pass'),
                self::cbSuccess(__('telegram.city.btn_hall'), 'ob:hall'),
            ],
        ];

        foreach (self::services($city)['inline_keyboard'] as $row) {
            $rows[] = $row;
        }

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function gates(City $city): array
    {
        $rows = [];

        if ($city->has_portal) {
            $rows[] = [self::cb(__('telegram.city.btn_portal'), 'city:portal')];
        }

        if ($city->has_forest) {
            $rows[] = [self::cb(__('telegram.city.btn_forest'), 'city:forest')];
        }

        $rows[] = [self::cb(__('telegram.city.btn_back'), 'city:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function overseerOffer(): array
    {
        return self::inline([
            [
                self::cbDanger(__('telegram.city.btn_not_now'), 'ob:not_now'),
                self::cbSuccess(__('telegram.city.btn_hall'), 'ob:hall'),
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
            if ($target->portal_cost_silver > 0) {
                $text = __('telegram.city.portal_row', [
                    'name' => $target->name,
                    'cost' => $target->portal_cost_silver,
                ]);
            } else {
                $text = __('telegram.city.portal_row_free', ['name' => $target->name]);
            }

            $rows[] = [self::cb($text, 'portal:' . $target->id)];
        }

        $rows[] = [self::cb(__('telegram.city.btn_back'), 'menu:home')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function services(City $city): array
    {
        $rows = [
            [self::cb(__('telegram.city.btn_gates'), 'city:gates')],
            [self::cb(__('telegram.city.btn_tavern'), 'city:tavern')],
        ];

        if ($city->has_quest_board) {
            $rows[] = [self::cb(__('telegram.city.btn_board'), 'city:board')];
        }

        $rows[] = [self::cb(__('telegram.city.btn_arena'), 'city:arena')];

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function tavern(City $city, Character $character): array
    {
        $rows = [];

        if ($city->has_healer) {
            $rows[] = [self::cb(__('telegram.city.btn_healer'), 'city:healer')];
        }

        if ($city->has_blacksmith) {
            $rows[] = [self::cb(__('telegram.city.btn_blacksmith'), 'city:blacksmith')];
        }

        if ($city->has_buyer) {
            $rows[] = [self::cb(__('telegram.city.btn_buyer'), 'city:buyer')];
        }

        if ($character->onboarding_skipped) {
            $rows[] = [self::cb(__('telegram.city.btn_overseer'), 'city:overseer')];
        }

        $rows[] = [self::cb(__('telegram.city.btn_back'), 'city:home')];

        return self::inline($rows);
    }
}
