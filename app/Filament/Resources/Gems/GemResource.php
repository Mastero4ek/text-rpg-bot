<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gems;

use App\Filament\Resources\Gems\Pages\CreateGem;
use App\Filament\Resources\Gems\Pages\EditGem;
use App\Filament\Resources\Gems\Pages\ListGems;
use App\Filament\Resources\Gems\Pages\ViewGem;
use App\Filament\Resources\Gems\Schemas\GemForm;
use App\Filament\Resources\Gems\Tables\GemsTable;
use App\Models\Gem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class GemResource extends Resource
{
    protected static ?string $model = Gem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function canForceDelete(Model $record): bool
    {
        if (! $record instanceof Gem) {
            return false;
        }

        return ! $record->isReferenced();
    }

    public static function form(Schema $schema): Schema
    {
        return GemForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.gem.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.gems');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGems::route('/'),
            'create' => CreateGem::route('/create'),
            'view' => ViewGem::route('/{record}'),
            'edit' => EditGem::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.gem.plural');
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
        return GemsTable::configure($table);
    }
}
