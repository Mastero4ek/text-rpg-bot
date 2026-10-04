<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Schemas;

use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightStepEnum;
use App\Filament\Support\CrossedSwordsIcon;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\VerticalAlignment;
use Filament\Support\Icons\Heroicon;

final class FightForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.fight_session'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            Select::make('kind')
                                ->label(__('admin.labels.fight_kind'))
                                ->options(FightKindEnum::class)
                                ->native(false),
                            Select::make('step')
                                ->label(__('admin.labels.fight_step'))
                                ->options(FightStepEnum::class)
                                ->native(false),
                            DateTimePicker::make('turn_deadline_at')
                                ->label(__('admin.labels.turn_deadline_at'))
                                ->displayFormat('d.m.Y H:i:s')
                                ->seconds(true)
                                ->native(false),
                        ])
                            ->columns(3)
                            ->columnSpanFull(),
                        Flex::make([
                            Group::make([
                                TextInput::make('character.username')
                                    ->label(__('admin.labels.username')),
                                Group::make([
                                    TextInput::make('player_hp')
                                        ->label(__('admin.labels.player_hp'))
                                        ->numeric(),
                                    TextInput::make('player_max_hp')
                                        ->label(__('admin.labels.player_max_hp'))
                                        ->numeric(),
                                    TextInput::make('player_stamina')
                                        ->label(__('admin.labels.player_stamina'))
                                        ->numeric(),
                                    TextInput::make('player_max_stamina')
                                        ->label(__('admin.labels.player_max_stamina'))
                                        ->numeric(),
                                ])
                                    ->columns(2),
                            ]),
                            Html::make(CrossedSwordsIcon::html())
                                ->grow(false),
                            Group::make([
                                TextInput::make('enemy.name')
                                    ->label(__('admin.labels.enemy')),
                                Group::make([
                                    TextInput::make('enemy.current_hp')
                                        ->label(__('admin.labels.player_hp'))
                                        ->numeric(),
                                    TextInput::make('enemy.maxHp')
                                        ->label(__('admin.labels.player_max_hp'))
                                        ->numeric(),
                                    TextInput::make('enemy.stamina')
                                        ->label(__('admin.labels.player_stamina'))
                                        ->numeric(),
                                    TextInput::make('enemy.maxStamina')
                                        ->label(__('admin.labels.player_max_stamina'))
                                        ->numeric(),
                                ])
                                    ->columns(2),
                            ]),
                        ])
                            ->from('md')
                            ->verticalAlignment(VerticalAlignment::Center)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('admin.sections.fight_log'))
                    ->icon(Heroicon::OutlinedQueueList)
                    ->columns(1)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Textarea::make('log')
                            ->hiddenLabel()
                            ->formatStateUsing(function (mixed $state): string {
                                if (! is_array($state) || $state === []) {
                                    return '';
                                }

                                $lines = [];

                                foreach ($state as $line) {
                                    if (is_string($line) && $line !== '') {
                                        $lines[] = $line;
                                    }
                                }

                                return implode("\n", $lines);
                            })
                            ->rows(12)
                            ->dehydrated(false),
                    ]),
            ]);
    }
}
