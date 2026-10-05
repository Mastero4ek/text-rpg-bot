<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Schemas;

use App\Filament\Support\InventoryDurabilityText;
use App\Filament\Support\InventoryQuantityText;
use App\Models\Inventory;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class InventoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('admin.sections.inventory_instance'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->columns(3)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        SpatieMediaLibraryImageEntry::make('equipment.image')
                            ->label(__('admin.labels.appearance'))
                            ->collection('image')
                            ->circular()
                            ->imageSize(40)
                            ->placeholder('-'),
                        TextEntry::make('item_id')
                            ->label(__('admin.labels.item_id'))
                            ->placeholder('-'),
                        TextEntry::make('item_name')
                            ->label(__('admin.labels.item_name'))
                            ->placeholder('-'),
                        TextEntry::make('quantity')
                            ->label(__('admin.labels.quantity'))
                            ->state(fn (Inventory $record): ?string => InventoryQuantityText::format($record))
                            ->placeholder('-'),
                        TextEntry::make('item_type')
                            ->label(__('admin.labels.item_type'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('slot')
                            ->label(__('admin.labels.slot'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('durability')
                            ->label(__('admin.labels.durability_pair'))
                            ->state(fn (Inventory $record): ?string => InventoryDurabilityText::format($record))
                            ->placeholder('-'),
                        ViewEntry::make('socketed_gems')
                            ->label(__('admin.labels.socketed_gems'))
                            ->view('filament.components.socketed-gems'),
                        IconEntry::make('is_equipped')
                            ->label(__('admin.labels.is_equipped'))
                            ->state(fn (Inventory $record): bool => $record->isEquipped())
                            ->boolean(),
                        TextEntry::make('created_at')
                            ->label(__('admin.labels.obtained_at'))
                            ->dateTime('d.m.Y')
                            ->placeholder('-'),
                    ]),
                Section::make(__('admin.sections.inventory_owner'))
                    ->icon(Heroicon::OutlinedUser)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsed()
                    ->collapsible()
                    ->schema([
                        TextEntry::make('tg_id')
                            ->label(__('admin.labels.tg_id'))
                            ->placeholder('-'),
                        TextEntry::make('character.username')
                            ->label(__('admin.labels.username'))
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
