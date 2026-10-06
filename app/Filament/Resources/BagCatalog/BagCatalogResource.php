<?php

declare(strict_types=1);

namespace App\Filament\Resources\BagCatalog;

use App\Filament\Resources\BagCatalog\Pages\CreateBagCatalog;
use App\Filament\Resources\BagCatalog\Pages\EditBagCatalog;
use App\Filament\Resources\BagCatalog\Pages\ListBagCatalog;
use App\Filament\Resources\BagCatalog\Pages\ViewBagCatalog;
use App\Filament\Resources\BagCatalog\Schemas\BagCatalogForm;
use App\Filament\Resources\BagCatalog\Tables\BagCatalogTable;
use App\Models\BagCatalog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class BagCatalogResource extends Resource
{
    protected static ?string $model = BagCatalog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'bag-catalog';

    public static function canForceDelete(Model $record): bool
    {
        if (! $record instanceof BagCatalog) {
            return false;
        }

        return ! $record->isReferenced();
    }

    public static function form(Schema $schema): Schema
    {
        return BagCatalogForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.bag_catalog.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.bag_catalog');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBagCatalog::route('/'),
            'create' => CreateBagCatalog::route('/create'),
            'view' => ViewBagCatalog::route('/{record}'),
            'edit' => EditBagCatalog::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.bag_catalog.plural');
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
        return BagCatalogTable::configure($table);
    }
}
