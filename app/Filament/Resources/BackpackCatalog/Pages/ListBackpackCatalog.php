<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackpackCatalog\Pages;

use App\Filament\Resources\BackpackCatalog\BackpackCatalogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBackpackCatalog extends ListRecords
{
    protected static string $resource = BackpackCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
