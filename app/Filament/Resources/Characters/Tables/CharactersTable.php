<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Actions\Character\CharacterDeleteAction;
use App\Filament\Concerns\HasAppearanceColumn;
use App\Models\Character;
use App\Services\CharacterService;
use App\Services\GameConfig;
use Carbon\CarbonInterface;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class CharactersTable
{
    use HasAppearanceColumn;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                self::appearanceColumn(),
                TextColumn::make('username')
                    ->label(__('admin.labels.username'))
                    ->searchable(['username', 'tg_id'])
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn (Character $record): string => self::recordLabel($record))
                    ->placeholder('-'),
                TextColumn::make('exp')
                    ->label(__('admin.labels.exp'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('level')
                    ->label(__('admin.labels.level'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                IconColumn::make('banned')
                    ->label(__('admin.labels.banned'))
                    ->alignCenter()
                    ->boolean()
                    ->trueColor(Color::Green)
                    ->falseColor(Color::Red)
                    ->sortable(['banned_until'])
                    ->getStateUsing(fn (Character $record): bool => app(CharacterService::class)->hasActiveBan($record))
                    ->tooltip(fn (Character $record): ?string => self::untilTooltip($record->banned_until)),
                IconColumn::make('premium')
                    ->label(__('admin.labels.premium'))
                    ->alignCenter()
                    ->boolean()
                    ->trueColor(Color::Green)
                    ->falseColor(Color::Red)
                    ->sortable(['premium_until'])
                    ->getStateUsing(fn (Character $record): bool => app(CharacterService::class)->hasActivePremium($record))
                    ->tooltip(fn (Character $record): ?string => self::untilTooltip($record->premium_until)),
                TextColumn::make('created_at')
                    ->label(__('admin.labels.created_at'))
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label(__('admin.labels.updated_at'))
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('city_id')
                    ->label(__('admin.labels.city'))
                    ->relationship('city', 'name')
                    ->native(false),
                SelectFilter::make('level')
                    ->label(__('admin.labels.level'))
                    ->options(self::levelOptions())
                    ->native(false),
                TernaryFilter::make('banned')
                    ->label(__('admin.labels.banned'))
                    ->placeholder(__('admin.filters.all'))
                    ->trueLabel(__('admin.filters.banned_only'))
                    ->falseLabel(__('admin.filters.banned_without'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('banned_until', '>', now()),
                        false: fn (Builder $query): Builder => $query
                            ->where(function (Builder $inner): void {
                                $inner->whereNull('banned_until')
                                    ->orWhere('banned_until', '<=', now());
                            }),
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->native(false),
                TernaryFilter::make('premium')
                    ->label(__('admin.labels.premium'))
                    ->placeholder(__('admin.filters.all'))
                    ->trueLabel(__('admin.filters.premium_only'))
                    ->falseLabel(__('admin.filters.premium_without'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where('premium_until', '>', now()),
                        false: fn (Builder $query): Builder => $query
                            ->where(function (Builder $inner): void {
                                $inner->whereNull('premium_until')
                                    ->orWhere('premium_until', '<=', now());
                            }),
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->native(false),
                TrashedFilter::make()
                    ->label(__('admin.actions.trashed_filter.label'))
                    ->placeholder(__('admin.actions.trashed_filter.without'))
                    ->trueLabel(__('admin.actions.trashed_filter.with'))
                    ->falseLabel(__('admin.actions.trashed_filter.only'))
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make()
                    ->icon('heroicon-o-eye')
                    ->color(Color::Teal)
                    ->label('')
                    ->tooltip(__('admin.actions.view.label')),
                EditAction::make()
                    ->icon('heroicon-o-pencil')
                    ->color(Color::Sky)
                    ->label('')
                    ->tooltip(__('admin.actions.edit.label')),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color(Color::Amber)
                    ->label('')
                    ->tooltip(__('admin.actions.archive.label'))
                    ->modalHeading(fn (Character $record): string => __('admin.actions.archive.modal_heading', [
                        'label' => self::recordLabel($record),
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.archive.notification')),
                RestoreAction::make()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color(Color::Green)
                    ->label('')
                    ->tooltip(__('admin.actions.restore.label'))
                    ->modalHeading(fn (Character $record): string => __('admin.actions.restore.modal_heading', [
                        'label' => self::recordLabel($record),
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.restore.notification')),
                ForceDeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->color(Color::Red)
                    ->label('')
                    ->tooltip(__('admin.actions.delete.label'))
                    ->modalHeading(fn (Character $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => self::recordLabel($record),
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification'))
                    ->using(function (Character $record): bool {
                        app(CharacterDeleteAction::class)->handle($record);

                        return true;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('admin.actions.archive_bulk.label'))
                        ->modalHeading(__('admin.actions.archive_bulk.modal_heading'))
                        ->modalSubmitActionLabel(__('admin.actions.archive_bulk.modal_submit'))
                        ->successNotificationTitle(__('admin.actions.archive_bulk.notification'))
                        ->icon(null)
                        ->color(Color::Amber),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['media']))
            ->emptyStateHeading(__('admin.empty.characters.heading'))
            ->emptyStateDescription(__('admin.empty.characters.description'));
    }

    /**
     * @return array<int, string>
     */
    private static function levelOptions(): array
    {
        $character = app(GameConfig::class)->character();

        if (! array_key_exists('level', $character) || ! is_array($character['level'])) {
            throw new RuntimeException('character.level missing.');
        }

        if (! array_key_exists('max', $character['level']) || ! is_int($character['level']['max'])) {
            throw new RuntimeException('character.level.max missing.');
        }

        $options = [];

        for ($level = 0; $level <= $character['level']['max']; $level++) {
            $options[$level] = (string) $level;
        }

        return $options;
    }

    private static function recordLabel(Character $record): string
    {
        if (is_string($record->username) && $record->username !== '') {
            return $record->username;
        }

        return (string) $record->tg_id;
    }

    private static function untilTooltip(?CarbonInterface $until): ?string
    {
        if (! $until instanceof CarbonInterface) {
            return null;
        }

        if (! $until->isFuture()) {
            return null;
        }

        return $until->format('d.m.Y H:i');
    }
}
