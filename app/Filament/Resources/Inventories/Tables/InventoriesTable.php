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
        return self::configureWithColumns($table, [
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
            ...self::itemColumns(),
        ]);
    }

    public static function configureForCharacter(Table $table): Table
    {
        return self::configureWithColumns($table, [
            TextColumn::make('id')
                ->label('ID')
                ->sortable(),
            ...self::itemColumns(),
        ]);
    }

    /**
     * @return list<\Filament\Tables\Columns\Column>
     */
    private static function itemColumns(): array
    {
        return [
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
        ];
    }

    /**
     * @param  list<\Filament\Tables\Columns\Column>  $columns
     */
    private static function configureWithColumns(Table $table, array $columns): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns($columns)
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->headerActions([]);
    }
}
