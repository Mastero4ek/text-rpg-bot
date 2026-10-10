<?php

declare(strict_types=1);

namespace App\Telegram\Keyboards;

use App\Models\City;

final class RegistrationKeyboard
{
    use BuildsInlineKeyboard;

    /**
     * @param  list<City>  $cities
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function city(array $cities): array
    {
        $rows = [];

        foreach ($cities as $city) {
            $rows[] = [self::cb($city->name, 'ob:city:' . $city->key)];
        }

        return self::inline($rows);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function nickBack(): array
    {
        return self::inline([
            [self::cbDanger(__('telegram.registration.btn_back'), 'ob:back')],
        ]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public static function splash(): array
    {
        return self::inline([
            [self::cbSuccess(__('telegram.registration.btn_rise'), 'ob:rise')],
        ]);
    }
}
