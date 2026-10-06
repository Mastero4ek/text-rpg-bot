<?php

declare(strict_types=1);

namespace App\Filament\Resources\EnemyCatalog\Schemas;

use App\Enums\Enemy\EnemyKindEnum;
use App\Models\Bag\BagCatalog;
use App\Models\Enemy\EnemyCatalog;
use App\Services\CombatService;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class EnemyCatalogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('admin.sections.enemy_catalog_identity'))
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
                                    ->label(__('admin.labels.enemy_kind'))
                                    ->options(EnemyKindEnum::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, mixed $state, string $operation): void {
                                        self::resetFieldsForKind($set);
                                        self::syncCatalogId($set, $state, $operation);
                                    }),
                                TextInput::make('level')
                                    ->label(__('admin.labels.level'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === EnemyKindEnum::FIXED)
                                    ->required(fn (Get $get): bool => self::selectedKind($get('kind')) === EnemyKindEnum::FIXED),
                                TextInput::make('power_pct')
                                    ->label(__('admin.labels.power_pct'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.power_pct'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === EnemyKindEnum::MIRROR)
                                    ->required(fn (Get $get): bool => self::selectedKind($get('kind')) === EnemyKindEnum::MIRROR),
                            ])->columns(3),
                            Group::make([
                                Toggle::make('enabled')
                                    ->label(__('admin.labels.enabled'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.enabled')),
                                Toggle::make('in_fight_menu')
                                    ->label(__('admin.labels.in_fight_menu'))
                                    ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.in_fight_menu')),
                            ])->columns(3),
                        ]),
                        Group::make([
                            SpatieMediaLibraryFileUpload::make('image')
                                ->label(__('admin.labels.image'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.image'))
                                ->collection('image')
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp']),
                            Textarea::make('description')
                                ->label(__('admin.labels.description'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.description'))
                                ->rows(3),
                        ]),
                    ]),
                Section::make(__('admin.sections.enemy_catalog_kind'))
                    ->icon(Heroicon::OutlinedBolt)
                    ->columnSpanFull()
                    ->collapsed()
                    ->visible(fn (Get $get): bool => self::selectedKind($get('kind')) === EnemyKindEnum::FIXED)
                    ->schema([
                        Group::make([
                            TextInput::make('strength')
                                ->label(__('admin.labels.strength'))
                                ->numeric()
                                ->minValue(0)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set, mixed $state): void {
                                    $set('max_stamina', app(CombatService::class)->maxStamina(self::intState($state)));
                                }),
                            TextInput::make('agility')
                                ->label(__('admin.labels.agility'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('instinct')
                                ->label(__('admin.labels.instinct'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('vitality')
                                ->label(__('admin.labels.vitality'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                            ->columns(4)
                            ->columnSpanFull(),
                        Group::make([
                            TextInput::make('weapon_damage')
                                ->label(__('admin.labels.weapon_damage'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('max_hp')
                                ->label(__('admin.labels.max_hp'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('max_stamina')
                                ->label(__('admin.labels.max_stamina'))
                                ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.body_stamina'))
                                ->numeric()
                                ->disabled()
                                ->dehydrated(false)
                                ->afterStateHydrated(function (TextInput $component, Get $get): void {
                                    $component->state(app(CombatService::class)->maxStamina(self::intState($get('strength'))));
                                }),
                        ])
                            ->columns(4)
                            ->columnSpanFull(),
                        Group::make([
                            TextInput::make('mf_dodge')
                                ->label(__('admin.labels.mf_dodge'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('mf_anti_dodge')
                                ->label(__('admin.labels.mf_anti_dodge'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('mf_crit')
                                ->label(__('admin.labels.mf_crit'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                            TextInput::make('mf_anti_crit')
                                ->label(__('admin.labels.mf_anti_crit'))
                                ->numeric()
                                ->minValue(0)
                                ->required(),
                        ])
                            ->columns(4)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('admin.sections.enemy_catalog_rewards'))
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->columns(4)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        TextInput::make('reward_exp_pct')
                            ->label(__('admin.labels.reward_exp_pct'))
                            ->hintIcon(self::fieldHintIcon(), tooltip: __('admin.hints.reward_exp_pct'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('reward_silver_min')
                            ->label(__('admin.labels.reward_silver_min'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('reward_silver_max')
                            ->label(__('admin.labels.reward_silver_max'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                    ]),
                Section::make(__('admin.sections.enemy_catalog_drops'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        EmptyState::make(__('admin.empty.drops.heading'))
                            ->description(__('admin.empty.drops.description'))
                            ->icon(Heroicon::OutlinedXMark)
                            ->iconColor('gray')
                            ->contained(false)
                            ->visible(fn (string $operation, mixed $record): bool => self::viewHasNoDrops($operation, $record)),
                        Repeater::make('drops')
                            ->relationship()
                            ->hiddenLabel()
                            ->visible(fn (string $operation, mixed $record): bool => ! self::viewHasNoDrops($operation, $record))
                            ->schema([
                                Select::make('bag_catalog_id')
                                    ->label(__('admin.labels.item_name'))
                                    ->options(fn (): array => self::bagCatalogOptions())
                                    ->required()
                                    ->searchable()
                                    ->native(false)
                                    ->live(),
                                TextInput::make('chance_pct')
                                    ->label(__('admin.labels.chance_pct'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->required(),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->collapsed()
                            ->itemLabel(function (array $state): ?string {
                                if (! array_key_exists('bag_catalog_id', $state) || ! is_string($state['bag_catalog_id'])) {
                                    return null;
                                }

                                if ($state['bag_catalog_id'] === '') {
                                    return null;
                                }

                                $options = self::bagCatalogOptions();

                                if (! array_key_exists($state['bag_catalog_id'], $options)) {
                                    return $state['bag_catalog_id'];
                                }

                                return $options[$state['bag_catalog_id']];
                            })
                            ->addActionLabel(__('admin.actions.add')),
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
    private static function bagCatalogOptions(): array
    {
        $options = [];
        $rows = BagCatalog::query()
            ->orderBy('name')
            ->get();

        foreach ($rows as $row) {
            $options[$row->catalog_id] = $row->name;
        }

        return $options;
    }

    private static function viewHasNoDrops(string $operation, mixed $record): bool
    {
        if ($operation !== 'view') {
            return false;
        }

        if (! $record instanceof EnemyCatalog) {
            return true;
        }

        return $record->drops->isEmpty();
    }

    private static function intState(mixed $raw): int
    {
        if (is_int($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '' && is_numeric($raw)) {
            return (int) $raw;
        }

        return 0;
    }

    private static function resetFieldsForKind(Set $set): void
    {
        $set('level', null);
        $set('strength', null);
        $set('agility', null);
        $set('instinct', null);
        $set('vitality', null);
        $set('max_hp', null);
        $set('weapon_damage', null);
        $set('mf_dodge', null);
        $set('mf_anti_dodge', null);
        $set('mf_crit', null);
        $set('mf_anti_crit', null);
        $set('power_pct', null);
    }

    private static function selectedKind(mixed $raw): ?EnemyKindEnum
    {
        if ($raw instanceof EnemyKindEnum) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            return EnemyKindEnum::tryFrom($raw);
        }

        return null;
    }

    private static function syncCatalogId(Set $set, mixed $kindRaw, string $operation): void
    {
        if ($operation !== 'create') {
            return;
        }

        $kind = self::selectedKind($kindRaw);

        if (! $kind instanceof EnemyKindEnum) {
            $set('catalog_id', null);

            return;
        }

        $set('catalog_id', EnemyCatalog::nextCatalogIdForKind($kind));
    }
}
