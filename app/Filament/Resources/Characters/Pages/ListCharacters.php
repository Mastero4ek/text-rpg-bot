<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Pages;

use App\Filament\Resources\Characters\CharacterResource;
use Filament\Resources\Pages\ListRecords;

final class ListCharacters extends ListRecords
{
    protected static string $resource = CharacterResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
