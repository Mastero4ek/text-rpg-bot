<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Tables;

use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
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

final class EquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('item_id')
                    ->label(__('admin.labels.item_id'))
                    ->searchable()
                    ->tooltip(fn (Equipment $record): string => $record->item_id)
                    ->limit(15)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('admin.labels.name'))
                    ->searchable()
                    ->tooltip(fn (Equipment $record): string => $record->name)
                    ->limit(20)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('tier')
                    ->label(__('admin.labels.tier'))
                    ->placeholder('-')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('item_type')
                    ->label(__('admin.labels.item_type'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                IconColumn::make('in_shop')
                    ->label(__('admin.labels.in_shop'))
                    ->alignCenter()
                    ->sortable()
                    ->placeholder('-')
                    ->boolean(),
                IconColumn::make('enabled')
                    ->label(__('admin.labels.enabled'))
                    ->alignCenter()
                    ->sortable()
                    ->placeholder('-')
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
                SelectFilter::make('item_type')
                    ->label(__('admin.labels.item_type'))
                    ->native(false)
                    ->options(TypeEnum::class),
                SelectFilter::make('slot')
                    ->label(__('admin.labels.slot'))
                    ->native(false)
                    ->options(SlotEnum::class),
                SelectFilter::make('tier')
                    ->label(__('admin.labels.tier'))
                    ->native(false)
                    ->options([
                        0 => '0',
                        1 => '1',
                        2 => '2',
                        3 => '3',
                    ]),
                TernaryFilter::make('in_shop')
                    ->label(__('admin.labels.in_shop'))
                    ->native(false),
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
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color(Color::Amber)
                    ->label('')
                    ->tooltip(__('admin.actions.archive.label'))
                    ->modalHeading(fn (Equipment $record): string => __('admin.actions.archive.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.archive.notification')),
                RestoreAction::make()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color(Color::Green)
                    ->label('')
                    ->tooltip(__('admin.actions.restore.label'))
                    ->modalHeading(fn (Equipment $record): string => __('admin.actions.restore.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.restore.notification')),
                ForceDeleteAction::make()
                    ->icon('heroicon-o-trash')
                    ->color(Color::Red)
                    ->label('')
                    ->tooltip(__('admin.actions.delete.label'))
                    ->modalHeading(fn (Equipment $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification'))
                    ->visible(fn (Equipment $record): bool => $record->trashed() && ! $record->isReferencedByInventory()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('admin.actions.archive_bulk.label'))
                        ->modalHeading(__('admin.actions.archive_bulk.modal_heading'))
                        ->modalSubmitActionLabel(__('admin.actions.archive_bulk.modal_submit'))
                        ->successNotificationTitle(__('admin.actions.archive_bulk.notification'))
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->color(Color::Amber),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('media'));
    }
}
