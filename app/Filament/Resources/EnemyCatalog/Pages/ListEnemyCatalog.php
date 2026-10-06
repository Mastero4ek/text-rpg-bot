<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnemyCatalog\Pages;

use App\Filament\Resources\EnemyCatalog\EnemyCatalogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListEnemyCatalog extends ListRecords
{
    protected static string $resource = EnemyCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
