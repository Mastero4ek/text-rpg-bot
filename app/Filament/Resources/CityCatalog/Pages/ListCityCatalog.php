<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Pages;

use App\Filament\Resources\CityCatalog\CityCatalogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCityCatalog extends ListRecords
{
    protected static string $resource = CityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
