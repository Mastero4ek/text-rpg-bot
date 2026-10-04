<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights;

use App\Filament\Resources\Fights\Pages\ListFights;
use App\Filament\Resources\Fights\Pages\ViewFight;
use App\Filament\Resources\Fights\Schemas\FightInfolist;
use App\Filament\Resources\Fights\Tables\FightsTable;
use App\Models\Fight;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class FightResource extends Resource
{
    protected static ?string $model = Fight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?string $recordTitleAttribute = 'tg_id';

    protected static ?int $navigationSort = 4;

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

    public static function infolist(Schema $schema): Schema
    {
        return FightInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FightsTable::configure($table);
    }
}
