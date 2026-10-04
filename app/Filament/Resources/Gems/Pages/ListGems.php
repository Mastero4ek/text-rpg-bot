<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gems\Pages;

use App\Filament\Resources\Gems\GemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListGems extends ListRecords
{
    protected static string $resource = GemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
