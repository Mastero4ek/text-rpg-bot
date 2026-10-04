<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class InventoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id')
                            ->label('ID'),
                        TextEntry::make('tg_id')
                            ->label(__('admin.labels.tg_id')),
                        TextEntry::make('character.username')
                            ->label(__('admin.labels.username'))
                            ->placeholder('-'),
                        TextEntry::make('item_id')
                            ->label(__('admin.labels.item_id')),
                        TextEntry::make('item_name')
                            ->label(__('admin.labels.item_name')),
                        TextEntry::make('item_type')
                            ->label(__('admin.labels.item_type'))
                            ->badge(),
                        TextEntry::make('slot')
                            ->label(__('admin.labels.slot'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('durability')
                            ->label(__('admin.labels.durability'))
                            ->numeric()
                            ->placeholder('-'),
                        TextEntry::make('max_durability')
                            ->label(__('admin.labels.max_durability'))
                            ->numeric()
                            ->placeholder('-'),
                        IconEntry::make('is_equipped')
                            ->label(__('admin.labels.is_equipped'))
                            ->boolean(),
                    ]),
            ]);
    }
}
