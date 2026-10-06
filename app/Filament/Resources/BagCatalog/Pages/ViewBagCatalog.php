<?php

declare(strict_types=1);

namespace App\Filament\Resources\BagCatalog\Pages;

use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Resources\BagCatalog\BagCatalogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewBagCatalog extends ViewRecord
{
    use HasCloneToCreate;

    protected static string $resource = BagCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCloneAction(),
            EditAction::make(),
        ];
    }
}
