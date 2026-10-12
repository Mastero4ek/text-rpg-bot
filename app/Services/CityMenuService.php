<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\City\PveZoneEnum;
use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Queries\City\CityQuery;
use App\Services\DailyContract\DailyContractService;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Quest\QuestUnlockService;
use App\Telegram\Keyboards\CityKeyboard;

final class CityMenuService
{
    public function __construct(
        private readonly CityQuery $cities,
        private readonly DailyContractService $dailyContracts,
        private readonly QuestNpcPlacement $npcPlacement,
        private readonly QuestUnlockService $questUnlocks,
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

        $pveRows = [];

        foreach (PveZoneEnum::cases() as $zone) {
            if (! $zone->cityHas($city)) {
                continue;
            }

            $pveRows[] = [
                'text' => $this->locationButtonLabel(
                    $character,
                    $zone->location(),
                    __('telegram.btn.' . $zone->value),
                ),
                'callback_data' => $zone->callbackRoot(),
            ];
        }

        return CityKeyboard::gates(
            $city,
            $this->locationButtonLabel($character, LocationEnum::Portal, __('telegram.btn.portal')),
            $pveRows,
        );
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

        return CityKeyboard::services(
            $city,
            $this->locationButtonLabel($character, LocationEnum::Board, __('telegram.btn.board')),
            $this->locationButtonLabel($character, LocationEnum::Lists, __('telegram.btn.lists')),
        );
    }

    public function homeText(Character $character): string
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return __('errors.city_unavailable');
        }

        if ($city->description !== null && $city->description !== '') {
            return str_replace('\\n', "\n", $city->description);
        }

        return __('telegram.city.you_are_in');
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}|null
     */
    public function listsMarkup(Character $character): ?array
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return null;
        }

        return CityKeyboard::lists(
            $city,
            $this->locationButtonLabel($character, LocationEnum::Arena, __('telegram.btn.arena')),
        );
    }

    /**
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string, style?: string}>>}
     */
    public function portalMarkup(Character $character, string $filter, int $page): array
    {
        if ($character->city_id === null) {
            return CityKeyboard::backToGates();
        }

        $targets = [];
        $totalCount = 0;

        foreach ($this->cities->portalTargets($character->city_id) as $target) {
            $totalCount++;

            if ($filter === 'free' && $target->portal_cost_silver > 0) {
                continue;
            }

            if ($filter === 'paid' && $target->portal_cost_silver <= 0) {
                continue;
            }

            $targets[] = $target;
        }

        return CityKeyboard::portalTargets($targets, $filter, $page, $totalCount);
    }

    public function tavernHasNpc(Character $character): bool
    {
        $city = $this->currentCity($character);

        if (! $city instanceof City) {
            return false;
        }

        if ($this->cityFullyOpen($character)) {
            return $city->has_healer
                || $city->has_blacksmith
                || $city->has_buyer
                || $city->has_overseer;
        }

        return $this->npcPlacement->isOverseerAtTavern($character)
            || $this->npcPlacement->isHealerAtTavern($character)
            || $this->npcPlacement->isBlacksmithAtTavern($character)
            || $this->npcPlacement->isBuyerAtTavern($character);
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

        if ($this->cityFullyOpen($character)) {
            return CityKeyboard::tavern($city, $this->dailyContracts->turnInableIssuerKeys($character, $city));
        }

        $npcKeys = [];

        if ($this->npcPlacement->isHealerAtTavern($character)) {
            $npcKeys[] = 'healer';
        }

        if ($this->npcPlacement->isBlacksmithAtTavern($character)) {
            $npcKeys[] = 'blacksmith';
        }

        if ($this->npcPlacement->isBuyerAtTavern($character)) {
            $npcKeys[] = 'buyer';
        }

        if ($this->npcPlacement->isOverseerAtTavern($character)) {
            $npcKeys[] = 'overseer';
        }

        return CityKeyboard::tavernQuest($city, $npcKeys);
    }

    private function cityFullyOpen(Character $character): bool
    {
        if ($character->city_unlocked_full) {
            return true;
        }

        return $character->quest_phase === QuestPhaseEnum::Done;
    }

    private function locationButtonLabel(
        Character $character,
        LocationEnum $location,
        string $label,
    ): string {
        if ($this->questUnlocks->canOpen($character, $location)) {
            return $label;
        }

        return '🔒 ' . $label;
    }
}
