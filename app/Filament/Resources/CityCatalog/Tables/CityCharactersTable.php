<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Tables;

use App\Actions\Character\CharacterDeleteAction;
use App\Filament\Concerns\HasAppearanceColumn;
use App\Filament\Resources\Characters\CharacterResource;
use App\Models\Character;
use App\Services\GameConfig;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class CityCharactersTable
{
    use HasAppearanceColumn;

    public static function configure(Table $table, bool $editable): Table
    {
        $recordActions = [
            ViewAction::make()
                ->icon('heroicon-o-eye')
                ->color(Color::Teal)
                ->label('')
                ->tooltip(__('admin.actions.view.label'))
                ->url(fn (Character $record): string => CharacterResource::getUrl('view', [
                    'record' => $record,
                ])),
            EditAction::make()
                ->icon('heroicon-o-pencil')
                ->color(Color::Sky)
                ->label('')
                ->tooltip(__('admin.actions.edit.label'))
                ->url(fn (Character $record): string => CharacterResource::getUrl('edit', [
                    'record' => $record,
                ])),
        ];

        if ($editable) {
            $recordActions[] = DeleteAction::make()
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color(Color::Amber)
                ->label('')
                ->tooltip(__('admin.actions.archive.label'))
                ->modalHeading(fn (Character $record): string => __('admin.actions.archive.modal_heading', [
                    'label' => self::recordLabel($record),
                ]))
                ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
                ->successNotificationTitle(__('admin.actions.archive.notification'));
            $recordActions[] = RestoreAction::make()
                ->icon('heroicon-o-arrow-uturn-left')
                ->color(Color::Green)
                ->label('')
                ->tooltip(__('admin.actions.restore.label'))
                ->modalHeading(fn (Character $record): string => __('admin.actions.restore.modal_heading', [
                    'label' => self::recordLabel($record),
                ]))
                ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                ->successNotificationTitle(__('admin.actions.restore.notification'));
            $recordActions[] = ForceDeleteAction::make()
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
                });
        }

        return $table
            ->defaultSort('created_at', 'desc')
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
                TextColumn::make('silver')
                    ->label(__('admin.labels.silver'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('gold')
                    ->label(__('admin.labels.gold'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.labels.obtained_at'))
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('level')
                    ->label(__('admin.labels.level'))
                    ->options(self::levelOptions())
                    ->native(false),
                TrashedFilter::make()
                    ->label(__('admin.actions.trashed_filter.label'))
                    ->placeholder(__('admin.actions.trashed_filter.without'))
                    ->trueLabel(__('admin.actions.trashed_filter.with'))
                    ->falseLabel(__('admin.actions.trashed_filter.only'))
                    ->native(false),
            ])
            ->recordActions($recordActions)
            ->toolbarActions([])
            ->headerActions([])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['media']))
            ->emptyStateHeading(__('admin.empty.city_characters.heading'))
            ->emptyStateDescription(__('admin.empty.city_characters.description'));
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
}
