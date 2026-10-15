<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights;

use App\Filament\Resources\Fights\Pages\ListPvpFights;
use App\Filament\Resources\Fights\Pages\ViewPvpDuel;
use App\Filament\Resources\Fights\Schemas\PvpDuelForm;
use App\Filament\Resources\Fights\Tables\PvpDuelsTable;
use App\Models\Pvp\PvpDuel;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PvpFightResource extends Resource
{
    protected static ?string $model = PvpDuel::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'fights-pvp';

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
        return PvpDuelForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['sideA.media', 'sideB.media', 'fights']);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.pvp_duel.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.fights_pvp');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPvpFights::route('/'),
            'view' => ViewPvpDuel::route('/{record}'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.pvp_duel.plural');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof PvpDuel) {
            return null;
        }

        $a = PvpDuelForm::sideUsername($record->sideA?->username, $record->a_tg_id);
        $b = PvpDuelForm::sideUsername($record->sideB?->username, $record->b_tg_id);

        return $a . ' ⚔ ' . $b;
    }

    public static function table(Table $table): Table
    {
        return PvpDuelsTable::configure($table);
    }
}
