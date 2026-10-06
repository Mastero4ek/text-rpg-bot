<?php

declare(strict_types=1);

namespace App\Filament\Resources\BagCatalog\Schemas;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Support\CityCatalogField;
use App\Models\Bag\BagCatalog;
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

final class BagCatalogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.bag_catalog_identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            Hidden::make('catalog_id')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->dehydrated(),
                            TextInput::make('name')
                                ->label(__('admin.labels.name'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.name'))
                                ->required()
                                ->maxLength(255),
                            Group::make([
                                Select::make('kind')
                                    ->label(__('admin.labels.bag_kind'))
                                    ->options(BagKindEnum::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                                        self::resetFieldsForKind($set);
                                        self::syncCatalogId($set, $state, null, null, $operation);
                                    }),
                                Select::make('profile_pending')
                                    ->label(__('admin.labels.profile'))
                                    ->options([])
                                    ->native(false)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => ! self::selectedKind($get('kind')) instanceof BagKindEnum),
                                Select::make('type')
                                    ->label(__('admin.labels.profile'))
                                    ->options(GemTypeEnum::class)
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::GEM)
                                    ->required(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::GEM)
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state, string $operation): void {
                                        self::resetMfForType($set);
                                        self::syncCatalogId($set, $get('kind'), $state, null, $operation);
                                    }),
                                Select::make('profile')
                                    ->label(__('admin.labels.profile'))
                                    ->options(self::potionProfileOptions())
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::POTION)
                                    ->required(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::POTION)
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state, string $operation): void {
                                        self::syncCatalogId($set, $get('kind'), null, $state, $operation);
                                    }),
                                TextInput::make('mf_dodge')
                                    ->label(__('admin.labels.mf_dodge'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::EMERALD)
                                    ->required(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::EMERALD),
                                TextInput::make('mf_anti_dodge')
                                    ->label(__('admin.labels.mf_anti_dodge'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::DIAMOND)
                                    ->required(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::DIAMOND),
                                TextInput::make('mf_crit')
                                    ->label(__('admin.labels.mf_crit'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::RUBY)
                                    ->required(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::RUBY),
                                TextInput::make('mf_anti_crit')
                                    ->label(__('admin.labels.mf_anti_crit'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_mf'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::SAPPHIRE)
                                    ->required(fn (Get $get): bool => self::selectedGemType($get('type')) === GemTypeEnum::SAPPHIRE),
                                TextInput::make('effect_value')
                                    ->label(__('admin.labels.effect_value'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.effect_value'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::POTION)
                                    ->required(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::POTION),
                            ])
                                ->columns(3),
                            Group::make([
                                Toggle::make('enabled')
                                    ->label(__('admin.labels.enabled'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_enabled')),
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
                Section::make(__('admin.sections.bag_catalog_durability'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::GEM)
                    ->schema([
                        TextInput::make('max_durability')
                            ->label(__('admin.labels.durability'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_max_durability'))
                            ->numeric()
                            ->minValue(1)
                            ->required(fn (Get $get): bool => self::selectedKind($get('kind')) === BagKindEnum::GEM),
                    ]),
                Section::make(__('admin.sections.bag_catalog_economy'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        CityCatalogField::make()
                            ->columnSpan(2),
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

    /**
     * @return array<string, string>
     */
    private static function potionProfileOptions(): array
    {
        $options = [];

        foreach ([ProfileEnum::HEAL, ProfileEnum::STAMINA] as $profile) {
            $options[$profile->value] = $profile->getLabel();
        }

        return $options;
    }

    private static function resetFieldsForKind(Set $set): void
    {
        $set('type', null);
        $set('profile', null);
        $set('max_durability', null);
        $set('effect_value', null);
        $set('catalog_id', null);
        self::resetMfForType($set);
    }

    private static function resetMfForType(Set $set): void
    {
        $set('mf_dodge', null);
        $set('mf_anti_dodge', null);
        $set('mf_crit', null);
        $set('mf_anti_crit', null);
    }

    private static function selectedGemType(mixed $state): ?GemTypeEnum
    {
        if ($state instanceof GemTypeEnum) {
            return $state;
        }

        if (! is_string($state) || $state === '') {
            return null;
        }

        return GemTypeEnum::tryFrom($state);
    }

    private static function selectedKind(mixed $state): ?BagKindEnum
    {
        if ($state instanceof BagKindEnum) {
            return $state;
        }

        if (! is_string($state) || $state === '') {
            return null;
        }

        return BagKindEnum::tryFrom($state);
    }

    private static function selectedProfile(mixed $state): ?ProfileEnum
    {
        if ($state instanceof ProfileEnum) {
            return $state;
        }

        if (! is_string($state) || $state === '') {
            return null;
        }

        return ProfileEnum::tryFrom($state);
    }

    private static function syncCatalogId(
        Set $set,
        mixed $kindState,
        mixed $typeState,
        mixed $profileState,
        string $operation,
    ): void {
        if ($operation !== 'create') {
            return;
        }

        $kind = self::selectedKind($kindState);

        if ($kind === BagKindEnum::GEM) {
            $type = self::selectedGemType($typeState);

            if (! $type instanceof GemTypeEnum) {
                $set('catalog_id', null);

                return;
            }

            $set('catalog_id', BagCatalog::nextCatalogIdForType($type));

            return;
        }

        if ($kind === BagKindEnum::POTION) {
            $profile = self::selectedProfile($profileState);

            if (! $profile instanceof ProfileEnum) {
                $set('catalog_id', null);

                return;
            }

            $set('catalog_id', BagCatalog::nextCatalogIdForProfile($profile));

            return;
        }

        $set('catalog_id', null);
    }
}
