<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog;

use App\Filament\Resources\CityCatalog\Pages\CreateCityCatalog;
use App\Filament\Resources\CityCatalog\Pages\EditCityCatalog;
use App\Filament\Resources\CityCatalog\Pages\ListCityCatalog;
use App\Filament\Resources\CityCatalog\Pages\ViewCityCatalog;
use App\Filament\Resources\CityCatalog\RelationManagers\CharactersRelationManager;
use App\Filament\Resources\CityCatalog\Schemas\CityCatalogForm;
use App\Filament\Resources\CityCatalog\Tables\CityCatalogTable;
use App\Models\City;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class CityCatalogResource extends Resource
{
    protected static ?string $model = City::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'city-catalog';

    public static function canForceDelete(Model $record): bool
    {
        if (! $record instanceof City) {
            return false;
        }

        return ! $record->isReferencedByCharacters();
    }

    public static function configureArchiveAction(DeleteAction $action): DeleteAction
    {
        return $action
            ->modalHeading(fn (City $record): string => __('admin.actions.archive.modal_heading', [
                'label' => $record->name,
            ]))
            ->modalDescription(function (City $record): ?string {
                $count = $record->referencingCharactersCount();

                if ($count === 0) {
                    return null;
                }

                return __('admin.actions.archive.city_referenced', [
                    'count' => $count,
                ]);
            })
            ->modalSubmitAction(function (Action $action, mixed $record): Action|false {
                if ($record instanceof City && $record->isReferencedByCharacters()) {
                    return false;
                }

                return $action;
            })
            ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
            ->modalCancelActionLabel(function (mixed $record): string {
                if ($record instanceof City && $record->isReferencedByCharacters()) {
                    return __('admin.actions.archive.city_referenced_close');
                }

                return __('filament-actions::modal.actions.cancel.label');
            })
            ->successNotificationTitle(__('admin.actions.archive.notification'))
            ->visible(fn (City $record): bool => ! $record->trashed())
            ->before(function (DeleteAction $action, City $record): void {
                if ($record->isReferencedByCharacters()) {
                    $action->halt();
                }
            });
    }

    public static function form(Schema $schema): Schema
    {
        return CityCatalogForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.city_catalog.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.city_catalog');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCityCatalog::route('/'),
            'create' => CreateCityCatalog::route('/create'),
            'view' => ViewCityCatalog::route('/{record}'),
            'edit' => EditCityCatalog::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.city_catalog.plural');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationGroup::make(__('admin.navigation.characters'), [
                CharactersRelationManager::class,
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return CityCatalogTable::configure($table);
    }
}
