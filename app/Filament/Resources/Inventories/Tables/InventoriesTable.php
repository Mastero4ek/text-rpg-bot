<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class InventoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('tg_id')
                    ->label(__('admin.labels.tg_id'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('character.username')
                    ->label(__('admin.labels.username'))
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('item_name')
                    ->label(__('admin.labels.item_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('item_id')
                    ->label(__('admin.labels.item_id'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('item_type')
                    ->label(__('admin.labels.item_type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('slot')
                    ->label(__('admin.labels.slot'))
                    ->badge()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('stat_bonus')
                    ->label(__('admin.labels.stat_bonus'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weapon_class')
                    ->label(__('admin.labels.weapon_class'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('durability')
                    ->label(__('admin.labels.durability'))
                    ->numeric()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('max_durability')
                    ->label(__('admin.labels.max_durability'))
                    ->numeric()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_equipped')
                    ->label(__('admin.labels.is_equipped'))
                    ->boolean(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
