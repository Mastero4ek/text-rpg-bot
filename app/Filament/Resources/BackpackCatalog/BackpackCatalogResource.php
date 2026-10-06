<?php

declare(strict_types=1);

namespace App\Filament\Resources\BackpackCatalog;

use App\Filament\Resources\BackpackCatalog\Pages\CreateBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\EditBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\ListBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Pages\ViewBackpackCatalog;
use App\Filament\Resources\BackpackCatalog\Schemas\BackpackCatalogForm;
use App\Filament\Resources\BackpackCatalog\Tables\BackpackCatalogTable;
use App\Models\BackpackCatalog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class BackpackCatalogResource extends Resource
{
    protected static ?string $model = BackpackCatalog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'backpack-catalog';

    public static function canForceDelete(Model $record): bool
    {
        if (! $record instanceof BackpackCatalog) {
            return false;
        }

        return ! $record->isReferencedByBackpack();
    }

    public static function form(Schema $schema): Schema
    {
        return BackpackCatalogForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.backpack_catalog.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.backpack_catalog');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackpackCatalog::route('/'),
            'create' => CreateBackpackCatalog::route('/create'),
            'view' => ViewBackpackCatalog::route('/{record}'),
            'edit' => EditBackpackCatalog::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.backpack_catalog.plural');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function table(Table $table): Table
    {
        return BackpackCatalogTable::configure($table);
    }
}
