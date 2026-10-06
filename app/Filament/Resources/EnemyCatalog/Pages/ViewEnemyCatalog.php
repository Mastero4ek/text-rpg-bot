<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnemyCatalog\Pages;

use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Resources\EnemyCatalog\EnemyCatalogResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewEnemyCatalog extends ViewRecord
{
    use HasCloneToCreate;

    protected static string $resource = EnemyCatalogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCloneAction(),
            EditAction::make(),
        ];
    }
}
