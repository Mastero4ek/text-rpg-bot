<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Telegram\Keyboards\CityKeyboard;

final class CityMenuService
{
    public function __construct(
        private readonly CityQuery $cities,
    ) {}

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null
     */
    public function arenaMarkup(Character $character): ?array
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return null;
        }

        return CityKeyboard::arena($city);
    }

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

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null
     */
    public function gatesMarkup(Character $character): ?array
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return null;
        }

        return CityKeyboard::gates($city);
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null
     */
    public function homeMarkup(Character $character): ?array
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return null;
        }

        if ($character->progress_step === ProgressStepEnum::ARRIVED) {
            return CityKeyboard::firstHome();
        }

        return CityKeyboard::services($city);
    }

    public function homeText(Character $character): string
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return __('errors.city_unavailable');
        }

        if ($character->progress_step === ProgressStepEnum::ARRIVED) {
            return __('telegram.npc.first_home');
        }

        if ($city->description !== null && $city->description !== '') {
            return str_replace('\\n', "\n", $city->description);
        }

        return __('telegram.city.you_are_in');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public function portalMarkup(Character $character): array
    {
        if ($character->city_id === null) {
            return CityKeyboard::backToGates();
        }

        return CityKeyboard::portalTargets($this->cities->portalTargets($character->city_id));
    }

    public function tavernHasNpc(Character $character): bool
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return false;
        }

        if ($city->has_healer || $city->has_blacksmith || $city->has_buyer) {
            return true;
        }

        return $character->onboarding_skipped;
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null
     */
    public function tavernMarkup(Character $character): ?array
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return null;
        }

        return CityKeyboard::tavern($city, $character);
    }
}
