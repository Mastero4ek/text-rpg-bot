<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackpackCatalog\Pages;

use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Resources\BackpackCatalog\BackpackCatalogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewBackpackCatalog extends ViewRecord
{
    use HasCloneToCreate;

    protected static string $resource = BackpackCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCloneAction(),
            EditAction::make(),
        ];
    }
}
