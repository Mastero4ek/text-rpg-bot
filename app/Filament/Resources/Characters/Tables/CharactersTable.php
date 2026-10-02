<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class CharactersTable
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
                TextColumn::make('username')
                    ->label(__('admin.labels.username'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('location')
                    ->label(__('admin.labels.location'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('onboarding_step')
                    ->label(__('admin.labels.onboarding_step'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('level')
                    ->label(__('admin.labels.level'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('gold')
                    ->label(__('admin.labels.gold'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('current_hp')
                    ->label(__('admin.labels.current_hp'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('arena_points')
                    ->label(__('admin.labels.arena_points'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('premium_until')
                    ->label(__('admin.labels.premium_until'))
                    ->dateTime()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('exp')
                    ->label(__('admin.labels.exp'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('strength')
                    ->label(__('admin.labels.strength'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('agility')
                    ->label(__('admin.labels.agility'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('instinct')
                    ->label(__('admin.labels.instinct'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vitality')
                    ->label(__('admin.labels.vitality'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('weapon_id')
                    ->label(__('admin.labels.weapon_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('armor_id')
                    ->label(__('admin.labels.armor_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stat_points')
                    ->label(__('admin.labels.stat_points'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('potions')
                    ->label(__('admin.labels.potions'))
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_hp_update')
                    ->label(__('admin.labels.last_hp_update'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('admin.labels.created_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('admin.labels.updated_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
