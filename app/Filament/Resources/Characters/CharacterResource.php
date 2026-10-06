<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters;

use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Filament\Resources\Characters\Schemas\CharacterForm;
use App\Filament\Resources\Characters\Tables\CharactersTable;
use App\Models\Character;
use BackedEnum;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class CharacterResource extends Resource
{
    protected static ?string $model = Character::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'username';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return CharacterForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['loadoutSlots']);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.character.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.characters');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCharacters::route('/'),
            'view' => ViewCharacter::route('/{record}'),
            'edit' => EditCharacter::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.character.plural');
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
                LoadoutRelationManager::class,
                BackpackRelationManager::class,
                BagRelationManager::class,
            ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return CharactersTable::configure($table);
    }
}
