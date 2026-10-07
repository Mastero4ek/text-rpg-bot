<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\Schemas;

use App\Models\City;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class CityCatalogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.city_identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            Hidden::make('key')
                                ->unique(ignoreRecord: true)
                                ->dehydrated(),
                            TextInput::make('name')
                                ->label(__('admin.labels.name'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.city_name'))
                                ->required()
                                ->maxLength(255)
                                ->unique(ignoreRecord: true)
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                                    if ($operation !== 'create') {
                                        return;
                                    }

                                    if (! is_string($state) || $state === '') {
                                        return;
                                    }

                                    $set('key', City::nextKeyForName($state));
                                }),
                            Toggle::make('enabled')
                                ->label(__('admin.labels.enabled'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.city_enabled')),
                        ]),
                        Group::make([
                            SpatieMediaLibraryFileUpload::make('image')
                                ->label(__('admin.labels.image'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.image'))
                                ->collection('image')
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxFiles(1),
                            Textarea::make('description')
                                ->label(__('admin.labels.description'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.description'))
                                ->rows(3),
                        ]),
                    ]),
                Section::make(__('admin.sections.city_services'))
                    ->icon(Heroicon::OutlinedMapPin)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Group::make([
                            Toggle::make('has_blacksmith')->label(__('admin.labels.has_blacksmith')),
                            Toggle::make('has_healer')->label(__('admin.labels.has_healer')),
                            Toggle::make('has_buyer')->label(__('admin.labels.has_buyer')),
                            Toggle::make('has_quest_board')->label(__('admin.labels.has_quest_board')),
                            Toggle::make('has_portal')->label(__('admin.labels.has_portal')),
                            Toggle::make('has_forest')->label(__('admin.labels.has_forest')),
                            Toggle::make('has_fights_list')->label(__('admin.labels.has_fights_list')),
                            Toggle::make('has_training_room')->label(__('admin.labels.has_training_room')),
                        ])->columns(3),
                    ]),
                Section::make(__('admin.sections.city_economy'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextInput::make('portal_cost_silver')
                            ->label(__('admin.labels.portal_cost_silver'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.portal_cost_silver'))
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ]),
            ]);
    }

    /**
     * @return Closure(string): (?Heroicon)
     */
    private static function fieldHintIcon(): Closure
    {
        return function (string $operation): ?Heroicon {
            if ($operation === 'view') {
                return null;
            }

            return Heroicon::OutlinedQuestionMarkCircle;
        };
    }
}
