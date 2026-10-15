<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights;

use App\Enums\Fight\FightKindEnum;
use App\Filament\Resources\Fights\Pages\ListPveFights;
use App\Filament\Resources\Fights\Schemas\FightForm;
use App\Filament\Resources\Fights\Tables\FightsTable;
use App\Models\Fight;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PveFightResource extends Resource
{
    protected static ?string $model = Fight::class;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'fights-pve';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return true;
    }

    public static function canDeleteAny(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return FightForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['character.media'])
            ->where('kind', '!=', FightKindEnum::PVP->value);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.fight.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.fights_pve');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPveFights::route('/'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.fight.plural');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return FightResource::getRecordTitle($record);
    }

    public static function table(Table $table): Table
    {
        return FightsTable::configurePve($table);
    }
}
