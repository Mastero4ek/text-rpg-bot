<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inventories\Tables;

use App\Actions\Inventory\InventoryDiscardAction;
use App\Actions\Inventory\InventoryEquipToSlotAction;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Support\EquipmentTypeProfileFilters;
use App\Filament\Support\InventoryDurabilityText;
use App\Filament\Support\InventoryEquipPreviewHtml;
use App\Filament\Support\InventoryQuantityText;
use App\Filament\Tables\Columns\AppearanceImageColumn;
use App\Models\Character;
use App\Models\Equipment;
use App\Models\Inventory;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\LoadoutService;
use App\Services\Shop\ShopCatalog;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use RuntimeException;

final class InventoriesTable
{
    public static function configure(Table $table): Table
    {
        return self::finishTable($table, [
            ...self::backpackItemColumns(),
            IconColumn::make('is_equipped')
                ->label(__('admin.labels.is_equipped'))
                ->state(fn (Inventory $record): bool => $record->isEquipped())
                ->boolean()
                ->alignCenter()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    if ($direction !== 'asc' && $direction !== 'desc') {
                        throw new RuntimeException('Invalid sort direction.');
                    }

                    return $query->orderByRaw(
                        'exists (select 1 from loadout_slots where loadout_slots.inventory_id = inventories.id) ' . $direction,
                    );
                })
                ->placeholder('-'),
            TextColumn::make('tg_id')
                ->label(__('admin.labels.tg_id'))
                ->alignCenter()
                ->sortable()
                ->searchable()
                ->placeholder('-'),
            TextColumn::make('character.username')
                ->label(__('admin.labels.username'))
                ->searchable()
                ->limit(40)
                ->tooltip(fn (Inventory $record): string => self::usernameLabel($record))
                ->placeholder('-'),
        ], [
            ...self::typeAndProfileFilters(),
            TernaryFilter::make('is_equipped')
                ->label(__('admin.labels.is_equipped'))
                ->native(false)
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereHas('loadoutSlot'),
                    false: fn (Builder $query): Builder => $query->whereDoesntHave('loadoutSlot'),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ], self::characterMutationActions(), self::inventoryInstanceViewAction());
    }

    public static function configureForCharacter(Table $table): Table
    {
        return self::finishTable(
            $table,
            self::backpackItemColumns(),
            self::typeAndProfileFilters(),
            self::characterMutationActions(),
            self::equipmentCatalogViewAction(),
        )
            ->recordUrl(fn (Inventory $record): ?string => self::equipmentCatalogUrl($record))
            ->emptyStateHeading(__('admin.empty.backpack.heading'))
            ->emptyStateDescription(__('admin.empty.backpack.description'));
    }

    public static function configureForCharacterView(Table $table): Table
    {
        return self::finishTable(
            $table,
            self::backpackItemColumns(),
            self::typeAndProfileFilters(),
            [],
            self::equipmentCatalogViewAction(),
        )
            ->recordUrl(fn (Inventory $record): ?string => self::equipmentCatalogUrl($record))
            ->emptyStateHeading(__('admin.empty.backpack.heading'))
            ->emptyStateDescription(__('admin.empty.backpack.description'));
    }

    /**
     * @return list<\Filament\Tables\Columns\Column>
     */
    private static function backpackItemColumns(): array
    {
        return [
            AppearanceImageColumn::make('equipment.image')
                ->label(__('admin.labels.appearance'))
                ->collection('image')
                ->circular()
                ->imageSize(40)
                ->alignCenter()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    if ($direction !== 'asc' && $direction !== 'desc') {
                        throw new RuntimeException('Invalid sort direction.');
                    }

                    $inventoryTable = $query->getModel()->getTable();

                    return $query->orderByRaw(
                        'exists (
                            select 1
                            from media
                            where media.model_type = ?
                              and media.model_id = ' . $inventoryTable . '.item_id
                              and media.collection_name = ?
                        ) ' . $direction,
                        [(new Equipment)->getMorphClass(), 'image'],
                    );
                }),
            TextColumn::make('item_name')
                ->label(__('admin.labels.item_name'))
                ->searchable(['item_name', 'item_id'])
                ->sortable()
                ->limit(40)
                ->tooltip(fn (Inventory $record): string => $record->item_name)
                ->placeholder('-'),
            TextColumn::make('quantity')
                ->label(__('admin.labels.quantity'))
                ->state(fn (Inventory $record): ?string => InventoryQuantityText::format($record))
                ->alignCenter()
                ->sortable()
                ->placeholder('-'),
            TextColumn::make('item_type')
                ->label(__('admin.labels.item_type'))
                ->badge()
                ->alignCenter()
                ->sortable()
                ->placeholder('-'),
            TextColumn::make('equipment.profile')
                ->label(__('admin.labels.profile'))
                ->badge()
                ->alignCenter()
                ->sortable()
                ->placeholder('-'),
            ViewColumn::make('socketed_gems')
                ->label(__('admin.labels.socketed_gems'))
                ->view('filament.components.socketed-gems')
                ->alignCenter()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    if ($direction !== 'asc' && $direction !== 'desc') {
                        throw new RuntimeException('Invalid sort direction.');
                    }

                    return $query->orderByRaw(
                        'exists (
                            select 1
                            from equipment
                            where equipment.item_id = inventories.item_id
                              and equipment.gem_slots > 0
                              and inventories.item_type not in (?, ?)
                        ) ' . $direction,
                        [TypeEnum::JEWELRY->value, TypeEnum::POTION->value],
                    );
                }),
            TextColumn::make('durability')
                ->label(__('admin.labels.durability_pair'))
                ->state(fn (Inventory $record): ?string => InventoryDurabilityText::format($record))
                ->alignCenter()
                ->sortable()
                ->placeholder('-'),
            TextColumn::make('created_at')
                ->label(__('admin.labels.obtained_at'))
                ->dateTime('d.m.Y')
                ->sinceTooltip()
                ->alignCenter()
                ->sortable()
                ->placeholder('-'),
        ];
    }

    /**
     * @param  list<\Filament\Tables\Columns\Column>  $columns
     * @param  list<\Filament\Tables\Filters\BaseFilter>  $filters
     * @param  list<Action>  $recordActions
     */
    private static function finishTable(
        Table $table,
        array $columns,
        array $filters,
        array $recordActions,
        Action $viewAction,
    ): Table {
        return $table
            ->defaultSort('id', 'asc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['equipment', 'loadoutSlot']))
            ->columns($columns)
            ->filters($filters)
            ->recordActions([
                $viewAction,
                ...$recordActions,
            ])
            ->toolbarActions([])
            ->headerActions([]);
    }

    private static function equipmentCatalogUrl(Inventory $record): ?string
    {
        if ($record->equipment === null) {
            return null;
        }

        return EquipmentResource::getUrl('view', [
            'record' => $record->item_id,
        ]);
    }

    private static function equipmentCatalogViewAction(): Action
    {
        return Action::make('view')
            ->icon('heroicon-o-eye')
            ->color(Color::Teal)
            ->label('')
            ->tooltip(__('admin.actions.view.label'))
            ->url(fn (Inventory $record): ?string => self::equipmentCatalogUrl($record))
            ->visible(fn (Inventory $record): bool => $record->equipment !== null);
    }

    private static function inventoryInstanceViewAction(): ViewAction
    {
        return ViewAction::make()
            ->icon('heroicon-o-eye')
            ->color(Color::Teal)
            ->label('')
            ->tooltip(__('admin.actions.view.label'));
    }

    /**
     * @return list<Action>
     */
    private static function characterMutationActions(): array
    {
        return [
            self::equipAction(),
            self::discardAction(),
        ];
    }

    private static function discardAction(): Action
    {
        return Action::make('discard')
            ->icon('heroicon-o-trash')
            ->color(Color::Red)
            ->label('')
            ->tooltip(__('admin.actions.discard.label'))
            ->visible(fn (Inventory $record): bool => ! $record->isEquipped())
            ->requiresConfirmation()
            ->modalHeading(fn (Inventory $record): string => __('admin.actions.discard.modal_heading', [
                'name' => app(InventoryService::class)->rowLabel($record),
            ]))
            ->modalDescription(__('admin.actions.discard.modal_description'))
            ->modalSubmitActionLabel(__('admin.actions.discard.modal_submit'))
            ->action(function (Inventory $record, Component $livewire): void {
                $character = $record->character;

                if (! $character instanceof Character) {
                    $character = Character::query()->find($record->tg_id);
                }

                if (! $character instanceof Character) {
                    throw new RuntimeException('Character not found for inventory row.');
                }

                $result = app(InventoryDiscardAction::class)->handle($character, $record->id);

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                if ($livewire instanceof BackpackRelationManager) {
                    if ($result->character instanceof Character) {
                        $livewire->ownerRecord = $result->character;
                    }

                    $livewire->dispatch('character-gems-changed')->to(BagRelationManager::class);
                }

                Notification::make()
                    ->title(__('admin.actions.discard.notification'))
                    ->success()
                    ->send();
            });
    }

    private static function equipAction(): Action
    {
        return Action::make('equip')
            ->icon('heroicon-o-plus-circle')
            ->color(Color::Green)
            ->label('')
            ->tooltip(__('admin.actions.equip.label'))
            ->visible(function (Inventory $record): bool {
                if ($record->isEquipped()) {
                    return false;
                }

                return app(ShopCatalog::class)->isEquippable($record->item_id);
            })
            ->requiresConfirmation()
            ->modalHeading(fn (Inventory $record): string => __('admin.actions.equip.modal_heading', [
                'name' => $record->item_name,
            ]))
            ->modalDescription('')
            ->modalSubmitActionLabel(__('admin.actions.equip.modal_submit'))
            ->fillForm(function (Inventory $record): array {
                $slots = self::equippableSlotsFor($record);

                if ($slots === []) {
                    return [];
                }

                return ['slot' => $slots[0]->value];
            })
            ->schema(function (Inventory $record): array {
                $slots = self::equippableSlotsFor($record);
                $fields = [];

                if (count($slots) >= 2) {
                    $options = [];

                    foreach ($slots as $slot) {
                        $options[$slot->value] = $slot->getLabel();
                    }

                    $fields[] = Select::make('slot')
                        ->label(__('admin.labels.slot'))
                        ->options($options)
                        ->required()
                        ->live()
                        ->native(false);
                } else {
                    $fields[] = Hidden::make('slot');
                }

                $fields[] = Placeholder::make('equip_stat_changes')
                    ->hiddenLabel()
                    ->content(function (Get $get) use ($record): HtmlString {
                        return self::equipStatChangesHtml($record, $get('slot'));
                    });

                return $fields;
            })
            ->action(function (Inventory $record, array $data, Component $livewire): void {
                $character = $record->character;

                if (! $character instanceof Character) {
                    $character = Character::query()->find($record->tg_id);
                }

                if (! $character instanceof Character) {
                    throw new RuntimeException('Character not found for inventory row.');
                }

                $slots = app(LoadoutService::class)->equippableSlots($character, $record);

                if ($slots === []) {
                    Notification::make()
                        ->title(__('errors.cannot_equip'))
                        ->danger()
                        ->send();

                    return;
                }

                if (count($slots) === 1) {
                    $slot = $slots[0];
                } else {
                    if (! array_key_exists('slot', $data) || ! is_string($data['slot']) || $data['slot'] === '') {
                        throw new RuntimeException('Equip slot is required.');
                    }

                    $slot = SlotEnum::from($data['slot']);
                }

                $result = app(InventoryEquipToSlotAction::class)->handle($character, $record->id, $slot);

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                if ($livewire instanceof BackpackRelationManager && $result->character instanceof Character) {
                    $livewire->ownerRecord = $result->character;
                }

                $livewire->dispatch('character-loadout-changed')->to(LoadoutRelationManager::class);
                $livewire->dispatch('character-vitals-changed')->to(EditCharacter::class);
                $livewire->dispatch('character-vitals-changed')->to(ViewCharacter::class);

                Notification::make()
                    ->title(__('admin.actions.equip.notification'))
                    ->success()
                    ->send();
            });
    }

    private static function equipStatChangesHtml(Inventory $record, mixed $slotState): HtmlString
    {
        $character = $record->character;

        if (! $character instanceof Character) {
            $character = Character::query()->find($record->tg_id);
        }

        if (! $character instanceof Character) {
            return new HtmlString(e(__('errors.item_not_found')));
        }

        $slots = app(LoadoutService::class)->equippableSlots($character, $record);

        if ($slots === []) {
            return new HtmlString(e(__('errors.cannot_equip')));
        }

        if (is_string($slotState) && $slotState !== '') {
            $slot = SlotEnum::from($slotState);
        } else {
            $slot = $slots[0];
        }

        return InventoryEquipPreviewHtml::format(
            app(LoadoutService::class)->equipStatChanges($character, $record, $slot),
        );
    }

    /**
     * @return list<SlotEnum>
     */
    private static function equippableSlotsFor(Inventory $record): array
    {
        $character = $record->character;

        if (! $character instanceof Character) {
            $character = Character::query()->find($record->tg_id);
        }

        if (! $character instanceof Character) {
            return [];
        }

        return app(LoadoutService::class)->equippableSlots($character, $record);
    }

    /**
     * @return list<\Filament\Tables\Filters\BaseFilter>
     */
    private static function typeAndProfileFilters(): array
    {
        return [
            EquipmentTypeProfileFilters::itemType(),
            EquipmentTypeProfileFilters::profile()
                ->query(function (Builder $query, array $data): Builder {
                    $value = $data['value'] ?? null;

                    if (! is_string($value) || $value === '') {
                        return $query;
                    }

                    return $query->whereHas(
                        'equipment',
                        fn (Builder $equipment): Builder => $equipment->where('profile', $value),
                    );
                }),
            TernaryFilter::make('has_socketed_gems')
                ->label(__('admin.labels.has_socketed_gems'))
                ->native(false)
                ->trueLabel(__('admin.labels.with_socketed_gems'))
                ->falseLabel(__('admin.labels.without_socketed_gems'))
                ->queries(
                    true: fn (Builder $query): Builder => $query
                        ->whereNotNull('socketed_gems')
                        ->where('socketed_gems', '!=', '[]')
                        ->where('socketed_gems', 'like', '%"gem_id"%'),
                    false: fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->whereNull('socketed_gems')
                            ->orWhere('socketed_gems', '=', '[]')
                            ->orWhere('socketed_gems', 'not like', '%"gem_id"%');
                    }),
                    blank: fn (Builder $query): Builder => $query,
                ),
            TernaryFilter::make('has_gem_sockets')
                ->label(__('admin.labels.has_gem_sockets'))
                ->native(false)
                ->trueLabel(__('admin.labels.with_gem_sockets'))
                ->falseLabel(__('admin.labels.without_gem_sockets'))
                ->queries(
                    true: fn (Builder $query): Builder => $query
                        ->whereNotIn('item_type', [TypeEnum::JEWELRY->value, TypeEnum::POTION->value])
                        ->whereHas(
                            'equipment',
                            fn (Builder $equipment): Builder => $equipment->where('gem_slots', '>', 0),
                        ),
                    false: fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->whereIn('item_type', [TypeEnum::JEWELRY->value, TypeEnum::POTION->value])
                            ->orWhereDoesntHave('equipment')
                            ->orWhereHas(
                                'equipment',
                                fn (Builder $equipment): Builder => $equipment->where(function (Builder $slots): void {
                                    $slots->whereNull('gem_slots')
                                        ->orWhere('gem_slots', '<=', 0);
                                }),
                            );
                    }),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ];
    }

    private static function usernameLabel(Inventory $record): string
    {
        $username = $record->character?->username;

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) $record->tg_id;
    }
}
