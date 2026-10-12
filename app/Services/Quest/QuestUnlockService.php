<?php

declare(strict_types=1);

namespace App\Services\Quest;

use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\Character\Character;
use App\Models\City;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramHtml;

final class QuestUnlockService
{
    public function canOpen(Character $character, LocationEnum $location): bool
    {
        if (
            $character->city_unlocked_full
            || $character->quest_phase === QuestPhaseEnum::Done
        ) {
            return true;
        }

        return match ($location) {
            LocationEnum::Gates,
            LocationEnum::Tavern,
            LocationEnum::Training => true,
            LocationEnum::Portal => $character->unlock_portal === true,
            LocationEnum::Forest => $character->unlock_forest === true,
            LocationEnum::Swamp,
            LocationEnum::Ruins,
            LocationEnum::Port => false,
            LocationEnum::Board => $character->unlock_board === true,
            LocationEnum::Lists,
            LocationEnum::Arena => ! $character->healer_debt_open && $character->unlock_arena === true,
        };
    }

    public function canTravelByPortal(Character $character): bool
    {
        if ($character->city_unlocked_full) {
            return true;
        }

        return $character->quest_phase === QuestPhaseEnum::Done;
    }

    public function fogText(Character $character): string
    {
        $character->loadMissing('city');

        $cityName = '';

        if ($character->city instanceof City) {
            $cityName = TelegramHtml::escape($character->city->name);
        }

        return str_replace(':city', $cityName, LangVariant::pick('telegram.quest.fog'));
    }
}
