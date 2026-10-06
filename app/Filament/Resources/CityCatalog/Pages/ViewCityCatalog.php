<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Pages;

use App\Filament\Resources\CityCatalog\CityCatalogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCityCatalog extends ViewRecord
{
    protected static string $resource = CityCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
