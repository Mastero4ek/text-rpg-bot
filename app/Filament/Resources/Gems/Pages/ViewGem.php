<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gems\Pages;

use App\Filament\Concerns\HasCloneToCreate;
use App\Filament\Resources\Gems\GemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewGem extends ViewRecord
{
    use HasCloneToCreate;

    protected static string $resource = GemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCloneAction(),
            EditAction::make(),
        ];
    }
}
