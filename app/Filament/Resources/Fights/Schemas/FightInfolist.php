<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use RuntimeException;

final class FightInfolist
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
                        TextEntry::make('character.username')
                            ->label(__('admin.labels.username'))
                            ->placeholder('-'),
                        TextEntry::make('kind')
                            ->label(__('admin.labels.kind'))
                            ->badge(),
                        IconEntry::make('tutorial')
                            ->label(__('admin.labels.tutorial'))
                            ->boolean(),
                        TextEntry::make('player_hp')
                            ->label(__('admin.labels.player_hp'))
                            ->numeric(),
                        TextEntry::make('player_max_hp')
                            ->label(__('admin.labels.player_max_hp'))
                            ->numeric(),
                        TextEntry::make('step')
                            ->label(__('admin.labels.step'))
                            ->badge(),
                        TextEntry::make('player_stance')
                            ->label(__('admin.labels.player_stance'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('player_attack')
                            ->label(__('admin.labels.player_attack'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('player_defend')
                            ->label(__('admin.labels.player_defend'))
                            ->badge()
                            ->placeholder('-'),
                        IconEntry::make('use_potion')
                            ->label(__('admin.labels.use_potion'))
                            ->boolean(),
                        TextEntry::make('enemy')
                            ->label(__('admin.labels.enemy'))
                            ->columnSpanFull()
                            ->formatStateUsing(function (mixed $state): string {
                                if (! is_array($state)) {
                                    return '-';
                                }

                                $encoded = json_encode(
                                    $state,
                                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                                );

                                if (! is_string($encoded)) {
                                    throw new RuntimeException('Failed to encode fight enemy.');
                                }

                                return $encoded;
                            }),
                        TextEntry::make('log')
                            ->label(__('admin.labels.log'))
                            ->columnSpanFull()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('-'),
                    ]),
            ]);
    }
}
