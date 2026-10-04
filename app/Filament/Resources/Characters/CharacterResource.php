<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters;

use App\Filament\Resources\Characters\Pages\ListCharacters;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Characters\RelationManagers\InventoriesRelationManager;
use App\Filament\Resources\Characters\Schemas\CharacterInfolist;
use App\Filament\Resources\Characters\Tables\CharactersTable;
use App\Models\Character;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CharacterResource extends Resource
{
    protected static ?string $model = Character::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'username';

    protected static ?int $navigationSort = 1;

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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['equippedInventories']);
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
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.character.plural');
    }

    public static function getRelations(): array
    {
        return [
            'inventories' => InventoriesRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return CharacterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CharactersTable::configure($table);
    }
}
