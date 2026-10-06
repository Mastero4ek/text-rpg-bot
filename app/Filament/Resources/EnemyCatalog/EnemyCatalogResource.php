<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnemyCatalog;

use App\Filament\Resources\EnemyCatalog\Pages\CreateEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Pages\EditEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Pages\ListEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Pages\ViewEnemyCatalog;
use App\Filament\Resources\EnemyCatalog\Schemas\EnemyCatalogForm;
use App\Filament\Resources\EnemyCatalog\Tables\EnemyCatalogTable;
use App\Models\Enemy\EnemyCatalog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class EnemyCatalogResource extends Resource
{
    protected static ?string $model = EnemyCatalog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'enemy-catalog';

    public static function canForceDelete(Model $record): bool
    {
        if (! $record instanceof EnemyCatalog) {
            return false;
        }

        return ! $record->isTutorial();
    }

    public static function form(Schema $schema): Schema
    {
        return EnemyCatalogForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.enemy_catalog.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.enemy_catalog');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnemyCatalog::route('/'),
            'create' => CreateEnemyCatalog::route('/create'),
            'view' => ViewEnemyCatalog::route('/{record}'),
            'edit' => EditEnemyCatalog::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.enemy_catalog.plural');
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
        return EnemyCatalogTable::configure($table);
    }
}
