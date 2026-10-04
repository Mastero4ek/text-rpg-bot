<?php

declare(strict_types=1);

namespace App\Filament\Resources\Gems\Schemas;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Models\Gem;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class GemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.gem_identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            Hidden::make('gem_id')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->dehydrated(),
                            TextInput::make('name')
                                ->label(__('admin.labels.name'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.name'))
                                ->required()
                                ->maxLength(255),
                            Group::make([
                                Select::make('type')
                                    ->label(__('admin.labels.item_type'))
                                    ->options(GemTypeEnum::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                                        self::resetMfForType($set);
                                        self::syncGemIdForType($set, $state, $operation);
                                    }),
                                TextInput::make('max_durability')
                                    ->label(__('admin.labels.max_durability'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_max_durability'))
                                    ->numeric()
                                    ->required()
                                    ->minValue(1),
                                TextInput::make('mf_pending')
                                    ->label(__('admin.labels.gem_stat'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => ! self::selectedType($get('type')) instanceof GemTypeEnum),
                                TextInput::make('mf_dodge')
                                    ->label(__('admin.labels.mf_dodge'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::EMERALD)
                                    ->required(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::EMERALD),
                                TextInput::make('mf_anti_dodge')
                                    ->label(__('admin.labels.mf_anti_dodge'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::DIAMOND)
                                    ->required(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::DIAMOND),
                                TextInput::make('mf_crit')
                                    ->label(__('admin.labels.mf_crit'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::RUBY)
                                    ->required(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::RUBY),
                                TextInput::make('mf_anti_crit')
                                    ->label(__('admin.labels.mf_anti_crit'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::SAPPHIRE)
                                    ->required(fn (Get $get): bool => self::selectedType($get('type')) === GemTypeEnum::SAPPHIRE),
                            ])
                                ->columns(3),
                            Group::make([
                                Toggle::make('enabled')
                                    ->label(__('admin.labels.enabled'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_enabled')),
                                Toggle::make('in_shop')
                                    ->label(__('admin.labels.in_shop')),
                            ])
                                ->columns(3),
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
                Section::make(__('admin.sections.gem_economy'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Select::make('currency')
                            ->label(__('admin.labels.currency'))
                            ->options(CurrencyEnum::class)
                            ->required()
                            ->native(false),
                        TextInput::make('price')
                            ->label(__('admin.labels.price'))
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

            return Heroicon::OutlinedInformationCircle;
        };
    }

    private static function resetMfForType(Set $set): void
    {
        $set('mf_dodge', null);
        $set('mf_anti_dodge', null);
        $set('mf_crit', null);
        $set('mf_anti_crit', null);
    }

    private static function selectedType(mixed $state): ?GemTypeEnum
    {
        if ($state instanceof GemTypeEnum) {
            return $state;
        }

        if (! is_string($state) || $state === '') {
            return null;
        }

        return GemTypeEnum::tryFrom($state);
    }

    private static function syncGemIdForType(Set $set, mixed $state, string $operation): void
    {
        if ($operation !== 'create') {
            return;
        }

        $type = self::selectedType($state);

        if (! $type instanceof GemTypeEnum) {
            return;
        }

        $set('gem_id', Gem::nextGemIdForType($type));
    }
}
