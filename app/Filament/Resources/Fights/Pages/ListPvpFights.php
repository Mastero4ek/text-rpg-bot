<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Pages;

use App\Filament\Resources\Fights\PvpFightResource;
use Filament\Resources\Pages\ListRecords;

final class ListPvpFights extends ListRecords
{
    protected static string $resource = PvpFightResource::class;

    public function getTitle(): string
    {
        return __('admin.navigation.fights_pvp');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
