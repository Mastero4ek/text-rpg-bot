<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories;

use App\Filament\Resources\Inventories\Pages\ListInventories;
use App\Filament\Resources\Inventories\Pages\ViewInventory;
use App\Filament\Resources\Inventories\Schemas\InventoryInfolist;
use App\Filament\Resources\Inventories\Tables\InventoriesTable;
use App\Models\Inventory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $recordTitleAttribute = 'item_name';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.inventory.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.inventories');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventories::route('/'),
            'view' => ViewInventory::route('/{record}'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.inventory.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return InventoryInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InventoriesTable::configure($table);
    }
}
