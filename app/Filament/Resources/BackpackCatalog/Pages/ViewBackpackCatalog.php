<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Resources\Equipment\EquipmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewEquipment extends ViewRecord
{
    use HasCloneToCreate;

    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCloneAction(),
            EditAction::make(),
        ];
    }
}
