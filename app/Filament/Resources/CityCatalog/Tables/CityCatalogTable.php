<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Tables;

use App\Filament\Concerns\HasAppearanceColumn;
use App\Filament\Resources\CityCatalog\CityCatalogResource;
use App\Models\City;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CityCatalogTable
{
    use HasAppearanceColumn;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                self::appearanceColumn(),
                TextColumn::make('name')
                    ->label(__('admin.labels.name'))
                    ->searchable(['name', 'key'])
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('birth_characters_count')
                    ->label(__('admin.labels.birth_characters'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->label(__('admin.labels.enabled'))
                    ->alignCenter()
                    ->sortable()
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->label(__('admin.labels.created_at'))
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->label(__('admin.labels.updated_at'))
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('enabled')
                    ->label(__('admin.labels.enabled'))
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
                CityCatalogResource::configureArchiveAction(
                    DeleteAction::make()
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->color(Color::Amber)
                        ->label('')
                        ->tooltip(__('admin.actions.archive.label')),
                ),
                RestoreAction::make()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color(Color::Green)
                    ->label('')
                    ->tooltip(__('admin.actions.restore.label'))
                    ->modalHeading(fn (City $record): string => __('admin.actions.restore.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.restore.notification')),
                ForceDeleteAction::make()
                    ->icon('heroicon-o-trash')
                    ->color(Color::Red)
                    ->label('')
                    ->tooltip(__('admin.actions.delete.label'))
                    ->visible(fn (City $record): bool => $record->trashed() && ! $record->isReferencedByCharacters())
                    ->modalHeading(fn (City $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('admin.actions.archive_bulk.label'))
                        ->modalHeading(__('admin.actions.archive_bulk.modal_heading'))
                        ->modalSubmitActionLabel(__('admin.actions.archive_bulk.modal_submit'))
                        ->successNotificationTitle(__('admin.actions.archive_bulk.notification'))
                        ->icon(null)
                        ->color(Color::Amber)
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            foreach ($records as $record) {
                                if (! $record instanceof City) {
                                    continue;
                                }

                                if (! $record->isReferencedByCharacters()) {
                                    continue;
                                }

                                Notification::make()
                                    ->warning()
                                    ->title(__('admin.actions.archive.city_referenced_bulk'))
                                    ->send();

                                $action->halt();
                            }
                        }),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['media'])->withCount('birthCharacters'))
            ->emptyStateHeading(__('admin.empty.city_catalog.heading'))
            ->emptyStateDescription(__('admin.empty.city_catalog.description'));
    }
}
