<?php

declare(strict_types=1);

namespace App\Enums\Npc;

enum NpcSpotEnum: string
{
    case Board = 'board';
    case Forest = 'forest';
    case Hub = 'hub';
    case Port = 'port';
    case Ruins = 'ruins';
    case Tavern = 'tavern';

    public function returnCallback(): string
    {
        return match ($this) {
            self::Board => 'city:board',
            self::Forest => 'city:forest',
            self::Hub => 'city:home',
            self::Port => 'city:port',
            self::Ruins => 'city:ruins',
            self::Tavern => 'city:tavern',
        };
    }
}
