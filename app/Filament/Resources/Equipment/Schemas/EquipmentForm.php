<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Schemas;

use App\Enums\Equipment\CurrencyEnum;
use App\Enums\Equipment\EffectTypeEnum;
use App\Enums\Equipment\EquipmentProfileEnum;
use App\Enums\Equipment\RepairTierEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use Closure;
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

final class EquipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.equipment_identity'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Group::make([
                            TextInput::make('item_id')
                                ->label(__('admin.labels.item_id'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.item_id'))
                                ->required()
                                ->maxLength(64)
                                ->unique(ignoreRecord: true)
                                ->disabledOn('edit'),
                            TextInput::make('name')
                                ->label(__('admin.labels.name'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.name'))
                                ->required()
                                ->maxLength(255),
                            TextInput::make('tier')
                                ->label(__('admin.labels.tier'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.tier'))
                                ->numeric()
                                ->minValue(0),
                            Group::make([
                                Select::make('item_type')
                                    ->label(__('admin.labels.item_type'))
                                    ->options(TypeEnum::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        self::syncDependentFieldsForItemType($get, $set, $state);
                                    }),
                                Select::make('profile')
                                    ->label(__('admin.labels.profile'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.profile'))
                                    ->options(fn (Get $get): array => self::profileOptionsForType($get('item_type')))
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                                        self::syncEffectForProfile($get, $set, $state);
                                    })
                                    ->disabled(fn (Get $get): bool => ! self::selectedType($get('item_type')) instanceof TypeEnum)
                                    ->required(fn (Get $get): bool => self::selectedType($get('item_type')) instanceof TypeEnum),
                                Select::make('slot')
                                    ->label(__('admin.labels.slot'))
                                    ->options(fn (Get $get): array => self::slotOptionsForType($get('item_type')))
                                    ->native(false)
                                    ->disabled(fn (Get $get): bool => ! self::selectedType($get('item_type')) instanceof TypeEnum)
                                    ->required(fn (Get $get): bool => self::selectedType($get('item_type')) instanceof TypeEnum),
                            ])->columns(3),
                            Group::make([
                                Toggle::make('enabled')
                                    ->label(__('admin.labels.enabled'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.enabled'))
                                    ->default(true),
                                Toggle::make('in_shop')
                                    ->label(__('admin.labels.in_shop')),
                                Toggle::make('vip_only')
                                    ->label(__('admin.labels.vip_only')),
                            ])->columns(3),
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
                Section::make(__('admin.sections.equipment_requirements'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextInput::make('req_level')
                            ->label(__('admin.labels.req_level'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_strength')
                            ->label(__('admin.labels.req_strength'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_agility')
                            ->label(__('admin.labels.req_agility'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_instinct')
                            ->label(__('admin.labels.req_instinct'))
                            ->numeric()
                            ->minValue(0),
                    ]),
                Section::make(__('admin.sections.equipment_combat'))
                    ->icon(Heroicon::OutlinedBolt)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->visible(fn (Get $get): bool => self::selectedType($get('item_type')) instanceof TypeEnum)
                    ->schema([
                        TextInput::make('stat_bonus')
                            ->label(__('admin.labels.stat_bonus'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.stat_bonus'))
                            ->numeric()
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsStatBonus(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsStatBonus(self::selectedType($get('item_type')))),
                        TextInput::make('weapon_damage')
                            ->label(__('admin.labels.weapon_damage'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.weapon_damage'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsWeaponDamage(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsWeaponDamage(self::selectedType($get('item_type')))),
                        TextInput::make('armor')
                            ->label(__('admin.labels.armor'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.armor'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsArmor(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsArmor(self::selectedType($get('item_type')))),
                        TextInput::make('mf_dodge')
                            ->label(__('admin.labels.mf_dodge'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_dodge'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        TextInput::make('mf_anti_dodge')
                            ->label(__('admin.labels.mf_anti_dodge'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_anti_dodge'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        TextInput::make('mf_crit')
                            ->label(__('admin.labels.mf_crit'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_crit'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        TextInput::make('mf_anti_crit')
                            ->label(__('admin.labels.mf_anti_crit'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_anti_crit'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        Select::make('effect_type')
                            ->label(__('admin.labels.effect_type'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.effect_type'))
                            ->options(EffectTypeEnum::class)
                            ->native(false)
                            ->visible(fn (Get $get): bool => self::typeShowsEffects(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsEffects(self::selectedType($get('item_type')))),
                        TextInput::make('effect_value')
                            ->label(__('admin.labels.effect_value'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.effect_value'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsEffects(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsEffects(self::selectedType($get('item_type')))),
                    ]),
                Section::make(__('admin.sections.equipment_durability'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Select::make('repair_tier')
                            ->label(__('admin.labels.repair_tier'))
                            ->options(RepairTierEnum::class)
                            ->required()
                            ->native(false)
                            ->default(RepairTierEnum::NORMAL),
                        TextInput::make('max_durability')
                            ->label(__('admin.labels.max_durability'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('durability_loss_per_fight')
                            ->label(__('admin.labels.durability_loss_per_fight'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.durability_loss_per_fight'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('gem_slots')
                            ->label(__('admin.labels.gem_slots'))
                            ->numeric()
                            ->minValue(0),
                        Toggle::make('repairable')
                            ->label(__('admin.labels.repairable'))
                            ->default(true)
                            ->columnStart(1),
                    ]),
                Section::make(__('admin.sections.equipment_economy'))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Select::make('currency')
                            ->label(__('admin.labels.currency'))
                            ->options(CurrencyEnum::class)
                            ->required()
                            ->native(false)
                            ->live()
                            ->default(CurrencyEnum::SILVER)
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                self::syncVipOnlyForCurrency($set, $state);
                            }),
                        TextInput::make('price')
                            ->label(__('admin.labels.price'))
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->default(0),
                    ]),
            ]);
    }

    private static function clearProfileIfIncompatible(Get $get, Set $set, TypeEnum $type): void
    {
        $rawProfile = $get('profile');
        $profile = null;

        if ($rawProfile instanceof EquipmentProfileEnum) {
            $profile = $rawProfile;
        } elseif (is_string($rawProfile) && $rawProfile !== '') {
            $profile = EquipmentProfileEnum::tryFrom($rawProfile);
        }

        if ($profile === null) {
            return;
        }

        if (! $profile->belongsToType($type)) {
            $set('profile', null);
        }
    }

    private static function clearSlotIfIncompatible(Get $get, Set $set, TypeEnum $type): void
    {
        $rawSlot = $get('slot');
        $slot = null;

        if ($rawSlot instanceof SlotEnum) {
            $slot = $rawSlot;
        } elseif (is_string($rawSlot) && $rawSlot !== '') {
            $slot = SlotEnum::tryFrom($rawSlot);
        }

        if ($slot === null) {
            return;
        }

        if (! $slot->belongsToType($type)) {
            $set('slot', null);
        }
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
    private static function profileOptionsForType(mixed $itemType): array
    {
        $type = self::selectedType($itemType);

        if (! $type instanceof TypeEnum) {
            return [];
        }

        $options = [];

        foreach (EquipmentProfileEnum::forType($type) as $profile) {
            $options[$profile->value] = $profile->getLabel();
        }

        return $options;
    }

    private static function resetIrrelevantCharacteristics(Set $set, TypeEnum $type): void
    {
        if ($type === TypeEnum::WEAPON) {
            $set('stat_bonus', 0);
            $set('armor', 0);
            $set('effect_type', null);
            $set('effect_value', null);

            return;
        }

        if ($type === TypeEnum::ARMOR) {
            $set('weapon_damage', 0);
            $set('effect_type', null);
            $set('effect_value', null);

            return;
        }

        if ($type === TypeEnum::JEWELRY) {
            $set('weapon_damage', 0);
            $set('armor', 0);
            $set('effect_type', null);
            $set('effect_value', null);

            return;
        }

        $set('weapon_damage', 0);
        $set('stat_bonus', 0);
        $set('armor', 0);
        $set('mf_dodge', 0);
        $set('mf_anti_dodge', 0);
        $set('mf_crit', 0);
        $set('mf_anti_crit', 0);
    }

    private static function selectedProfile(mixed $state): ?EquipmentProfileEnum
    {
        if ($state instanceof EquipmentProfileEnum) {
            return $state;
        }

        if (! is_string($state)) {
            return null;
        }

        if ($state === '') {
            return null;
        }

        return EquipmentProfileEnum::tryFrom($state);
    }

    private static function selectedType(mixed $state): ?TypeEnum
    {
        if ($state instanceof TypeEnum) {
            return $state;
        }

        if (! is_string($state)) {
            return null;
        }

        if ($state === '') {
            return null;
        }

        return TypeEnum::tryFrom($state);
    }

    /**
     * @return array<string, string>
     */
    private static function slotOptionsForType(mixed $itemType): array
    {
        $type = self::selectedType($itemType);

        if (! $type instanceof TypeEnum) {
            return [];
        }

        $options = [];

        foreach (SlotEnum::forType($type) as $slot) {
            $options[$slot->value] = $slot->getLabel();
        }

        return $options;
    }

    private static function syncDependentFieldsForItemType(Get $get, Set $set, mixed $state): void
    {
        $type = self::selectedType($state);

        if (! $type instanceof TypeEnum) {
            $set('slot', null);
            $set('profile', null);

            return;
        }

        if ($type === TypeEnum::WEAPON) {
            self::clearSlotIfIncompatible($get, $set, $type);

            if ($get('slot') === null || $get('slot') === '') {
                $set('slot', SlotEnum::RIGHT_HAND->value);
            }

            self::clearProfileIfIncompatible($get, $set, $type);
            self::resetIrrelevantCharacteristics($set, $type);

            return;
        }

        if ($type === TypeEnum::POTION) {
            self::clearSlotIfIncompatible($get, $set, $type);

            if ($get('slot') === null || $get('slot') === '') {
                $set('slot', SlotEnum::POCKET->value);
            }

            self::clearProfileIfIncompatible($get, $set, $type);
            self::resetIrrelevantCharacteristics($set, $type);
            self::syncEffectForProfile($get, $set, $get('profile'));

            return;
        }

        if ($type === TypeEnum::JEWELRY) {
            self::clearSlotIfIncompatible($get, $set, $type);

            if ($get('slot') === null || $get('slot') === '') {
                $set('slot', SlotEnum::AMULET->value);
            }

            self::clearProfileIfIncompatible($get, $set, $type);
            self::resetIrrelevantCharacteristics($set, $type);

            return;
        }

        self::clearSlotIfIncompatible($get, $set, $type);
        self::clearProfileIfIncompatible($get, $set, $type);
        self::resetIrrelevantCharacteristics($set, $type);
    }

    private static function syncEffectForProfile(Get $get, Set $set, mixed $state): void
    {
        if (self::selectedType($get('item_type')) !== TypeEnum::POTION) {
            return;
        }

        $profile = self::selectedProfile($state);

        if ($profile === EquipmentProfileEnum::HEAL) {
            $set('effect_type', EffectTypeEnum::HEAL_HP->value);
        }
    }

    private static function syncVipOnlyForCurrency(Set $set, mixed $state): void
    {
        $currency = null;

        if ($state instanceof CurrencyEnum) {
            $currency = $state;
        } elseif (is_string($state) && $state !== '') {
            $currency = CurrencyEnum::tryFrom($state);
        }

        $set('vip_only', $currency === CurrencyEnum::GOLD);
    }

    private static function typeShowsArmor(?TypeEnum $type): bool
    {
        return $type === TypeEnum::ARMOR;
    }

    private static function typeShowsEffects(?TypeEnum $type): bool
    {
        return $type === TypeEnum::POTION;
    }

    private static function typeShowsMf(?TypeEnum $type): bool
    {
        if ($type === TypeEnum::WEAPON) {
            return true;
        }

        if ($type === TypeEnum::ARMOR) {
            return true;
        }

        return $type === TypeEnum::JEWELRY;
    }

    private static function typeShowsStatBonus(?TypeEnum $type): bool
    {
        if ($type === TypeEnum::ARMOR) {
            return true;
        }

        return $type === TypeEnum::JEWELRY;
    }

    private static function typeShowsWeaponDamage(?TypeEnum $type): bool
    {
        return $type === TypeEnum::WEAPON;
    }
}
