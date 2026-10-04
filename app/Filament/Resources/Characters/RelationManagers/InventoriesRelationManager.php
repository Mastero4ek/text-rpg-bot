<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Filament\Resources\Inventories\InventoryResource;
use App\Filament\Resources\Inventories\Tables\InventoriesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class InventoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    protected static ?string $relatedResource = InventoryResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.inventories');
    }

    public function table(Table $table): Table
    {
        return InventoriesTable::configureForCharacter($table);
    }
}
