<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment;

use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipment;
use App\Filament\Resources\Equipment\Pages\ViewEquipment;
use App\Filament\Resources\Equipment\Schemas\EquipmentForm;
use App\Filament\Resources\Equipment\Tables\EquipmentTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 0;

    public static function canForceDelete(Model $record): bool
    {
        if (! $record instanceof Equipment) {
            return false;
        }

        return ! $record->isReferencedByInventory();
    }

    public static function form(Schema $schema): Schema
    {
        return EquipmentForm::configure($schema);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.equipment.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.equipment');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'view' => ViewEquipment::route('/{record}'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.equipment.plural');
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
        return EquipmentTable::configure($table);
    }
}
