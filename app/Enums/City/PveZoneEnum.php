<?php

declare(strict_types=1);

namespace App\Enums\City;

use App\Enums\Fight\FightReturnEnum;
use App\Enums\Quest\LocationEnum;
use App\Models\City;

enum PveZoneEnum: string
{
    case Forest = 'forest';
    case Swamp = 'swamp';
    case Ruins = 'ruins';
    case Port = 'port';

    public static function tryFromFightReturn(FightReturnEnum $returnTo): ?self
    {
        return match ($returnTo) {
            FightReturnEnum::Forest => self::Forest,
            FightReturnEnum::Swamp => self::Swamp,
            FightReturnEnum::Ruins => self::Ruins,
            FightReturnEnum::Port => self::Port,
            FightReturnEnum::Training => null,
        };
    }

    public function callbackRoot(): string
    {
        return 'city:' . $this->value;
    }

    public function cityHas(City $city): bool
    {
        return match ($this) {
            self::Forest => $city->has_forest,
            self::Swamp => $city->has_swamp,
            self::Ruins => $city->has_ruins,
            self::Port => $city->has_port,
        };
    }

    public function emptyErrorKey(): string
    {
        return 'telegram.location.error.no_' . $this->value;
    }

    public function emptyLocationKey(): string
    {
        return 'telegram.location.' . $this->value . '_empty';
    }

    public function fightReturn(): FightReturnEnum
    {
        return match ($this) {
            self::Forest => FightReturnEnum::Forest,
            self::Swamp => FightReturnEnum::Swamp,
            self::Ruins => FightReturnEnum::Ruins,
            self::Port => FightReturnEnum::Port,
        };
    }

    public function imageConfigKey(): string
    {
        return 'bot.city_' . $this->value . '_image';
    }

    public function location(): LocationEnum
    {
        return match ($this) {
            self::Forest => LocationEnum::Forest,
            self::Swamp => LocationEnum::Swamp,
            self::Ruins => LocationEnum::Ruins,
            self::Port => LocationEnum::Port,
        };
    }

    public function locationTextKey(): string
    {
        return 'telegram.location.' . $this->value;
    }
}
