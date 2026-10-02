<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CharacterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('tg_id')
                            ->label(__('admin.labels.tg_id')),
                        TextEntry::make('username')
                            ->label(__('admin.labels.username'))
                            ->placeholder('-'),
                        TextEntry::make('location')
                            ->label(__('admin.labels.location'))
                            ->placeholder('-'),
                        TextEntry::make('onboarding_step')
                            ->label(__('admin.labels.onboarding_step'))
                            ->badge(),
                        TextEntry::make('level')
                            ->label(__('admin.labels.level'))
                            ->numeric(),
                        TextEntry::make('exp')
                            ->label(__('admin.labels.exp'))
                            ->numeric(),
                        TextEntry::make('gold')
                            ->label(__('admin.labels.gold'))
                            ->numeric(),
                        TextEntry::make('current_hp')
                            ->label(__('admin.labels.current_hp'))
                            ->numeric(),
                        TextEntry::make('last_hp_update')
                            ->label(__('admin.labels.last_hp_update'))
                            ->dateTime(),
                        TextEntry::make('arena_points')
                            ->label(__('admin.labels.arena_points'))
                            ->numeric(),
                        TextEntry::make('premium_until')
                            ->label(__('admin.labels.premium_until'))
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('strength')
                            ->label(__('admin.labels.strength'))
                            ->numeric(),
                        TextEntry::make('agility')
                            ->label(__('admin.labels.agility'))
                            ->numeric(),
                        TextEntry::make('instinct')
                            ->label(__('admin.labels.instinct'))
                            ->numeric(),
                        TextEntry::make('vitality')
                            ->label(__('admin.labels.vitality'))
                            ->numeric(),
                        TextEntry::make('stat_points')
                            ->label(__('admin.labels.stat_points'))
                            ->numeric(),
                        TextEntry::make('potions')
                            ->label(__('admin.labels.potions'))
                            ->numeric(),
                        TextEntry::make('weapon_id')
                            ->label(__('admin.labels.weapon_id'))
                            ->placeholder('-'),
                        TextEntry::make('armor_id')
                            ->label(__('admin.labels.armor_id'))
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label(__('admin.labels.created_at'))
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label(__('admin.labels.updated_at'))
                            ->dateTime(),
                    ]),
            ]);
    }
}
