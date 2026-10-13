<?php

declare(strict_types=1);

namespace App\Enums\Npc;

enum NpcEnum: string
{
    case Overseer = 'overseer';
    case Healer = 'healer';
    case Blacksmith = 'blacksmith';
    case Buyer = 'buyer';
    case Bruiser = 'bruiser';
    case Barkeeper = 'barkeeper';

    public function deliverySpot(): NpcSpotEnum
    {
        return match ($this) {
            self::Healer => NpcSpotEnum::Ruins,
            self::Blacksmith => NpcSpotEnum::Forest,
            self::Buyer => NpcSpotEnum::Board,
            self::Bruiser => NpcSpotEnum::Port,
            self::Overseer => NpcSpotEnum::Hub,
            self::Barkeeper => NpcSpotEnum::Tavern,
        };
    }
}
