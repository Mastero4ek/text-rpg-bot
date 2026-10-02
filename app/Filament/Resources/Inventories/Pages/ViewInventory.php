<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Pages;

use App\Filament\Resources\Inventories\InventoryResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewInventory extends ViewRecord
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
