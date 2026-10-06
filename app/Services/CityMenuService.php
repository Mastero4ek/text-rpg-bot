<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Telegram\Keyboards\TelegramKeyboards;

final class CityMenuService
{
    public function __construct(
        private readonly CityQuery $cities,
    ) {}

    public function currentCity(Character $character): ?City
    {
        if ($character->city_id === null) {
            return null;
        }

        $character->loadMissing('city');

        if (! $character->city instanceof City) {
            return null;
        }

        return $character->city;
    }

    public function homeText(Character $character): string
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return __('errors.city_unavailable');
        }

        return __('city.you_are_in', ['name' => $city->name]);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}|null
     */
    public function homeMarkup(Character $character): ?array
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return null;
        }

        return TelegramKeyboards::cityServices($city);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function portalMarkup(Character $character): array
    {
        if ($character->city_id === null) {
            return TelegramKeyboards::backToCity();
        }

        return TelegramKeyboards::portalTargets($this->cities->portalTargets($character->city_id));
    }
}
