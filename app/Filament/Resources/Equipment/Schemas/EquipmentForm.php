<?php

declare(strict_types=1);

namespace App\Filament\Resources\Equipment\Schemas;

use App\Enums\Economy\CurrencyEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Equipment\RepairEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Models\Equipment;
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
                            Hidden::make('item_id')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->dehydrated(),
                            TextInput::make('name')
                                ->label(__('admin.labels.name'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.name'))
                                ->required()
                                ->maxLength(255),
                            Group::make([
                                Select::make('item_type')
                                    ->label(__('admin.labels.item_type'))
                                    ->options(TypeEnum::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state, string $operation): void {
                                        self::syncDependentFieldsForItemType($set, $state);
                                        self::syncItemIdForProfile($set, $get('profile'), $operation);
                                    }),
                                Select::make('profile')
                                    ->label(__('admin.labels.profile'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.profile'))
                                    ->options(fn (Get $get): array => self::profileOptionsForType($get('item_type')))
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                                        self::syncItemIdForProfile($set, $state, $operation);
                                    })
                                    ->disabled(fn (Get $get): bool => ! self::selectedType($get('item_type')) instanceof TypeEnum)
                                    ->required(fn (Get $get): bool => self::selectedType($get('item_type')) instanceof TypeEnum),
                                Select::make('slot')
                                    ->label(__('admin.labels.slot'))
                                    ->options(fn (Get $get): array => self::slotOptionsForType($get('item_type')))
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                                        self::syncArmorForSlot($set, $state);
                                    })
                                    ->disabled(fn (Get $get): bool => ! self::selectedType($get('item_type')) instanceof TypeEnum)
                                    ->required(fn (Get $get): bool => self::selectedType($get('item_type')) instanceof TypeEnum),
                            ])
                                ->columns(3),
                            Group::make([
                                Toggle::make('enabled')
                                    ->label(__('admin.labels.enabled'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.enabled')),
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
                Section::make(__('admin.sections.equipment_requirements'))
                    ->icon(Heroicon::OutlinedLockClosed)
                    ->columns(5)
                    ->columnSpanFull()
                    ->collapsed()
                    ->visible(fn (Get $get): bool => self::typeShowsRequirements(self::selectedType($get('item_type'))))
                    ->schema([
                        TextInput::make('req_level')
                            ->label(__('admin.labels.req_level'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.req_level'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_strength')
                            ->label(__('admin.labels.req_strength'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.req_strength'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_agility')
                            ->label(__('admin.labels.req_agility'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.req_agility'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_instinct')
                            ->label(__('admin.labels.req_instinct'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.req_instinct'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('req_vitality')
                            ->label(__('admin.labels.req_vitality'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.req_vitality'))
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
                            ->visible(fn (Get $get): bool => self::typeShowsStatBonus(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsStatBonus(self::selectedType($get('item_type')))),
                        TextInput::make('weapon_damage_min')
                            ->label(__('admin.labels.weapon_damage_min'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.weapon_damage_min'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsWeaponDamage(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsWeaponDamage(self::selectedType($get('item_type')))),
                        TextInput::make('weapon_damage_max')
                            ->label(__('admin.labels.weapon_damage_max'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.weapon_damage_max'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsWeaponDamage(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsWeaponDamage(self::selectedType($get('item_type'))))
                            ->gte('weapon_damage_min'),
                        TextInput::make('armor')
                            ->label(__('admin.labels.armor'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.armor'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::slotShowsZoneArmor(self::selectedSlot($get('slot'))))
                            ->required(fn (Get $get): bool => self::slotShowsZoneArmor(self::selectedSlot($get('slot')))),
                        TextInput::make('mf_dodge')
                            ->label(__('admin.labels.mf_dodge'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_dodge'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        TextInput::make('mf_anti_dodge')
                            ->label(__('admin.labels.mf_anti_dodge'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_anti_dodge'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        TextInput::make('mf_crit')
                            ->label(__('admin.labels.mf_crit'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_crit'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
                        TextInput::make('mf_anti_crit')
                            ->label(__('admin.labels.mf_anti_crit'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.mf_anti_crit'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type'))))
                            ->required(fn (Get $get): bool => self::typeShowsMf(self::selectedType($get('item_type')))),
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
                    ->visible(fn (Get $get): bool => self::typeShowsDurability(self::selectedType($get('item_type'))))
                    ->schema([
                        Select::make('repair_tier')
                            ->label(__('admin.labels.repair_tier'))
                            ->options(RepairEnum::class)
                            ->required()
                            ->native(false),
                        TextInput::make('max_durability')
                            ->label(__('admin.labels.durability'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('durability_loss_per_fight')
                            ->label(__('admin.labels.durability_loss_per_fight'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.durability_loss_per_fight'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('gem_slots')
                            ->label(__('admin.labels.gem_slots'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.gem_slots'))
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get): bool => self::typeShowsGemFields(self::selectedType($get('item_type')))),
                        Toggle::make('repairable')
                            ->label(__('admin.labels.repairable'))
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function sanitizeCatalogFieldsForType(array $data): array
    {
        $type = self::selectedType($data['item_type'] ?? null);

        if ($type === TypeEnum::WEAPON) {
            $data['stat_bonus'] = 0;
            $data['armor'] = 0;
            $data['effect_value'] = null;

            return $data;
        }

        if ($type === TypeEnum::ARMOR) {
            $data['weapon_damage_min'] = 0;
            $data['weapon_damage_max'] = 0;
            $data['effect_value'] = null;

            if (! self::slotShowsZoneArmor(self::selectedSlot($data['slot'] ?? null))) {
                $data['armor'] = 0;
            }

            return $data;
        }

        if ($type === TypeEnum::JEWELRY) {
            $data['weapon_damage_min'] = 0;
            $data['weapon_damage_max'] = 0;
            $data['armor'] = 0;
            $data['effect_value'] = null;
            $data['gem_slots'] = null;
            $data['max_durability'] = null;
            $data['durability_loss_per_fight'] = null;
            $data['repairable'] = false;
            $data['repair_tier'] = RepairEnum::NORMAL;

            return $data;
        }

        if ($type !== TypeEnum::POTION) {
            return $data;
        }

        $data['weapon_damage_min'] = 0;
        $data['weapon_damage_max'] = 0;
        $data['stat_bonus'] = 0;
        $data['armor'] = 0;
        $data['mf_dodge'] = 0;
        $data['mf_anti_dodge'] = 0;
        $data['mf_crit'] = 0;
        $data['mf_anti_crit'] = 0;
        $data['req_level'] = null;
        $data['req_strength'] = null;
        $data['req_agility'] = null;
        $data['req_instinct'] = null;
        $data['req_vitality'] = null;
        $data['max_durability'] = null;
        $data['durability_loss_per_fight'] = null;
        $data['gem_slots'] = null;
        $data['repairable'] = false;
        $data['repair_tier'] = RepairEnum::NORMAL;

        return $data;
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

        foreach (ProfileEnum::forType($type) as $profile) {
            $options[$profile->value] = $profile->getLabel();
        }

        return $options;
    }

    private static function resetIrrelevantCharacteristics(Set $set, TypeEnum $type): void
    {
        if ($type === TypeEnum::WEAPON) {
            $set('stat_bonus', null);
            $set('armor', null);
            $set('effect_value', null);

            return;
        }

        if ($type === TypeEnum::ARMOR) {
            $set('weapon_damage_min', null);
            $set('weapon_damage_max', null);
            $set('effect_value', null);

            return;
        }

        if ($type === TypeEnum::JEWELRY) {
            $set('weapon_damage_min', null);
            $set('weapon_damage_max', null);
            $set('armor', null);
            $set('effect_value', null);
            $set('gem_slots', null);
            $set('max_durability', null);
            $set('durability_loss_per_fight', null);
            $set('repairable', false);
            $set('repair_tier', RepairEnum::NORMAL);

            return;
        }

        $set('weapon_damage_min', null);
        $set('weapon_damage_max', null);
        $set('stat_bonus', null);
        $set('armor', null);
        $set('mf_dodge', null);
        $set('mf_anti_dodge', null);
        $set('mf_crit', null);
        $set('mf_anti_crit', null);
        $set('gem_slots', null);
        $set('req_level', null);
        $set('req_strength', null);
        $set('req_agility', null);
        $set('req_instinct', null);
        $set('req_vitality', null);
        $set('max_durability', null);
        $set('durability_loss_per_fight', null);
        $set('repairable', false);
        $set('repair_tier', RepairEnum::NORMAL);
    }

    private static function selectedProfile(mixed $state): ?ProfileEnum
    {
        if ($state instanceof ProfileEnum) {
            return $state;
        }

        if (! is_string($state)) {
            return null;
        }

        if ($state === '') {
            return null;
        }

        return ProfileEnum::tryFrom($state);
    }

    private static function selectedSlot(mixed $state): ?SlotEnum
    {
        if ($state instanceof SlotEnum) {
            return $state;
        }

        if (! is_string($state)) {
            return null;
        }

        if ($state === '') {
            return null;
        }

        return SlotEnum::tryFrom($state);
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

    private static function slotShowsZoneArmor(?SlotEnum $slot): bool
    {
        if (! $slot instanceof SlotEnum) {
            return false;
        }

        return in_array($slot, [
            SlotEnum::HELMET,
            SlotEnum::ARMOR,
            SlotEnum::PANTS,
            SlotEnum::BOOTS,
        ], true);
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

    private static function syncArmorForSlot(Set $set, mixed $slotState): void
    {
        if (self::slotShowsZoneArmor(self::selectedSlot($slotState))) {
            return;
        }

        $set('armor', null);
    }

    private static function syncDependentFieldsForItemType(Set $set, mixed $state): void
    {
        $set('slot', null);
        $set('profile', null);
        self::syncArmorForSlot($set, null);

        $type = self::selectedType($state);

        if (! $type instanceof TypeEnum) {
            return;
        }

        $slots = SlotEnum::forType($type);

        if (count($slots) === 1) {
            $set('slot', $slots[0]->value);
            self::syncArmorForSlot($set, $slots[0]->value);
        }

        self::resetIrrelevantCharacteristics($set, $type);
    }

    private static function syncItemIdForProfile(Set $set, mixed $profileState, string $operation): void
    {
        if ($operation !== 'create') {
            return;
        }

        $profile = self::selectedProfile($profileState);

        if (! $profile instanceof ProfileEnum) {
            $set('item_id', null);

            return;
        }

        $set('item_id', Equipment::nextItemIdForProfile($profile));
    }

    private static function typeShowsDurability(?TypeEnum $type): bool
    {
        if ($type === TypeEnum::WEAPON) {
            return true;
        }

        return $type === TypeEnum::ARMOR;
    }

    private static function typeShowsEffects(?TypeEnum $type): bool
    {
        return $type === TypeEnum::POTION;
    }

    private static function typeShowsGemFields(?TypeEnum $type): bool
    {
        if ($type === TypeEnum::WEAPON) {
            return true;
        }

        return $type === TypeEnum::ARMOR;
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

    private static function typeShowsRequirements(?TypeEnum $type): bool
    {
        if (! $type instanceof TypeEnum) {
            return false;
        }

        return $type !== TypeEnum::POTION;
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
