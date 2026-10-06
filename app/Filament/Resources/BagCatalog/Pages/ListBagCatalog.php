<?php

declare(strict_types=1);

namespace App\Filament\Resources\BagCatalog\Pages;

use App\Filament\Resources\BagCatalog\BagCatalogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBagCatalog extends ListRecords
{
    protected static string $resource = BagCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
