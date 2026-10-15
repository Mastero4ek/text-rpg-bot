<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals;

use App\Filament\Resources\Appeals\Pages\ListAppeals;
use App\Filament\Resources\Appeals\Pages\ViewAppeal;
use App\Filament\Resources\Appeals\Schemas\AppealForm;
use App\Filament\Resources\Appeals\Tables\AppealsTable;
use App\Models\Appeal\Appeal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class AppealResource extends Resource
{
    protected static ?string $model = Appeal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 1;

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
        return AppealForm::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['character.media']);
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.appeal.singular');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.appeals');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppeals::route('/'),
            'view' => ViewAppeal::route('/{record}'),
        ];
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.appeal.plural');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof Appeal) {
            return null;
        }

        $username = $record->character?->username;

        if (is_string($username) && $username !== '') {
            return $username . ' #' . $record->id;
        }

        return $record->tg_id . ' #' . $record->id;
    }

    public static function table(Table $table): Table
    {
        return AppealsTable::configure($table);
    }
}
