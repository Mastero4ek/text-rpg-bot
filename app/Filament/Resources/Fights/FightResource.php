<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights;

use App\Filament\Resources\Fights\Pages\ListFights;
use App\Filament\Resources\Fights\Pages\ViewFight;
use App\Filament\Resources\Fights\Schemas\FightForm;
use App\Filament\Resources\Fights\Tables\FightsTable;
use App\Models\Fight;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class FightResource extends Resource
{
    protected static ?string $model = Fight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?int $navigationSort = 5;

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
        return parent::getEloquentQuery()->with(['character']);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.fight.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.fights');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFights::route('/'),
            'view' => ViewFight::route('/{record}'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.fight.plural');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Fight) {
            return null;
        }

        $username = $record->character?->username;

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) $record->tg_id;
    }

    public static function table(Table $table): Table
    {
        return FightsTable::configure($table);
    }
}
