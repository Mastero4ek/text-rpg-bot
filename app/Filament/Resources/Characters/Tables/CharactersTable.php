<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Actions\Character\CharacterDeleteAction;
use App\Filament\Concerns\HasAppearanceColumn;
use App\Models\Character;
use App\Services\GameConfig;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
                TextColumn::make('location')
                    ->label(__('admin.labels.location'))
                    ->sortable()
                    ->searchable()
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
                SelectFilter::make('location')
                    ->label(__('admin.labels.location'))
                    ->options(self::locationOptions())
                    ->native(false),
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('media'));
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

    /**
     * @return array<string, string>
     */
    private static function locationOptions(): array
    {
        $onboarding = app(GameConfig::class)->onboarding();

        if (! array_key_exists('cityKeys', $onboarding) || ! is_array($onboarding['cityKeys'])) {
            throw new RuntimeException('onboarding.cityKeys missing.');
        }

        $options = [];

        foreach ($onboarding['cityKeys'] as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Invalid city key.');
            }

            $name = __('onboarding.cities.' . $key);
            $options[$name] = $name;
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
