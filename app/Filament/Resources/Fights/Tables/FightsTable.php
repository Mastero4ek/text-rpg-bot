<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class FightsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tg_id')
            ->columns([
                TextColumn::make('tg_id')
                    ->label(__('admin.labels.tg_id'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('character.username')
                    ->label(__('admin.labels.username'))
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('kind')
                    ->label(__('admin.labels.kind'))
                    ->badge()
                    ->sortable(),
                IconColumn::make('tutorial')
                    ->label(__('admin.labels.tutorial'))
                    ->boolean(),
                TextColumn::make('step')
                    ->label(__('admin.labels.step'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('player_hp')
                    ->label(__('admin.labels.player_hp'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('player_max_hp')
                    ->label(__('admin.labels.player_max_hp'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('player_stance')
                    ->label(__('admin.labels.player_stance'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('player_attack')
                    ->label(__('admin.labels.player_attack'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('player_defend')
                    ->label(__('admin.labels.player_defend'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('use_potion')
                    ->label(__('admin.labels.use_potion'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
