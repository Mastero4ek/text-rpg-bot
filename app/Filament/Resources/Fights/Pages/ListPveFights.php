<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Pages;

use App\Filament\Resources\Fights\PveFightResource;
use Filament\Resources\Pages\ListRecords;

final class ListPveFights extends ListRecords
{
    protected static string $resource = PveFightResource::class;

    public function getTitle(): string
    {
        return __('admin.navigation.fights_pve');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
