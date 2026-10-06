<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Actions\Backpack\BackpackDiscardAction;
use App\Actions\Backpack\BackpackEquipToSlotAction;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Equipment\TypeEnum;
use App\Filament\Resources\BackpackCatalog\BackpackCatalogResource;
use App\Filament\Resources\Characters\Pages\EditCharacter;
use App\Filament\Resources\Characters\Pages\ViewCharacter;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Filament\Support\BackpackDurabilityText;
use App\Filament\Support\BackpackEquipPreviewHtml;
use App\Filament\Support\EquipmentTypeProfileFilters;
use App\Filament\Tables\Columns\AppearanceImageColumn;
use App\Models\BackpackCatalog;
use App\Models\BackpackItem;
use App\Models\Character;
use App\Services\Backpack\BackpackService;
use App\Services\Backpack\LoadoutService;
use App\Services\Shop\ShopCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use RuntimeException;

final class BackpackTable
{
    public static function configure(Table $table, bool $canMutate): Table
    {
        $recordActions = [];

        if ($canMutate) {
            $recordActions = self::characterMutationActions();
        }

        return self::finishTable(
            $table,
            self::backpackItemColumns(),
            self::typeAndProfileFilters(),
            $recordActions,
            self::catalogViewAction(),
        )
            ->recordUrl(fn (BackpackItem $record): ?string => self::catalogUrl($record))
            ->emptyStateHeading(__('admin.empty.backpack.heading'))
            ->emptyStateDescription(__('admin.empty.backpack.description'));
    }

    /**
     * @return list<\Filament\Tables\Columns\Column>
     */
    private static function backpackItemColumns(): array
    {
        return [
            AppearanceImageColumn::make('catalog.image')
                ->label(__('admin.labels.appearance'))
                ->collection('image')
                ->circular()
                ->imageSize(40)
                ->alignCenter()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    if ($direction !== 'asc' && $direction !== 'desc') {
                        throw new RuntimeException('Invalid sort direction.');
                    }

                    $backpackTable = $query->getModel()->getTable();

                    return $query->orderByRaw(
                        'exists (
                            select 1
                            from media
                            where media.model_type = ?
                              and media.model_id = ' . $backpackTable . '.catalog_id
                              and media.collection_name = ?
                        ) ' . $direction,
                        [(new BackpackCatalog)->getMorphClass(), 'image'],
                    );
                }),
            TextColumn::make('item_name')
                ->label(__('admin.labels.item_name'))
                ->searchable(['item_name', 'catalog_id'])
                ->sortable()
                ->limit(40)
                ->tooltip(fn (BackpackItem $record): string => $record->item_name)
                ->placeholder('-'),
            TextColumn::make('item_type')
                ->label(__('admin.labels.item_type'))
                ->badge()
                ->alignCenter()
                ->sortable()
                ->placeholder('-'),
            TextColumn::make('catalog.profile')
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
                            from backpack_catalog
                            where backpack_catalog.catalog_id = backpack_items.catalog_id
                              and backpack_catalog.gem_slots > 0
                              and backpack_items.item_type != ?
                        ) ' . $direction,
                        [TypeEnum::JEWELRY->value],
                    );
                }),
            TextColumn::make('durability')
                ->label(__('admin.labels.durability_pair'))
                ->state(fn (BackpackItem $record): ?string => BackpackDurabilityText::format($record))
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['catalog', 'loadoutSlot']))
            ->columns($columns)
            ->filters($filters)
            ->recordActions([
                $viewAction,
                ...$recordActions,
            ])
            ->toolbarActions([])
            ->headerActions([]);
    }

    private static function catalogUrl(BackpackItem $record): ?string
    {
        if ($record->catalog === null) {
            return null;
        }

        return BackpackCatalogResource::getUrl('view', [
            'record' => $record->catalog_id,
        ]);
    }

    private static function catalogViewAction(): Action
    {
        return Action::make('view')
            ->icon('heroicon-o-eye')
            ->color(Color::Teal)
            ->label('')
            ->tooltip(__('admin.actions.view.label'))
            ->url(fn (BackpackItem $record): ?string => self::catalogUrl($record))
            ->visible(fn (BackpackItem $record): bool => $record->catalog !== null);
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
            ->visible(fn (BackpackItem $record): bool => ! $record->isEquipped())
            ->requiresConfirmation()
            ->modalHeading(fn (BackpackItem $record): string => __('admin.actions.discard.modal_heading', [
                'name' => app(BackpackService::class)->rowLabel($record),
            ]))
            ->modalDescription(__('admin.actions.discard.modal_description'))
            ->modalSubmitActionLabel(__('admin.actions.discard.modal_submit'))
            ->action(function (BackpackItem $record, Component $livewire): void {
                $character = $record->character;

                if (! $character instanceof Character) {
                    $character = Character::query()->find($record->tg_id);
                }

                if (! $character instanceof Character) {
                    throw new RuntimeException('Character not found for backpack row.');
                }

                $result = app(BackpackDiscardAction::class)->handle($character, $record->id);

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
            ->visible(function (BackpackItem $record): bool {
                if ($record->isEquipped()) {
                    return false;
                }

                return app(ShopCatalog::class)->isEquippable($record->catalog_id);
            })
            ->requiresConfirmation()
            ->modalHeading(fn (BackpackItem $record): string => __('admin.actions.equip.modal_heading', [
                'name' => $record->item_name,
            ]))
            ->modalDescription('')
            ->modalSubmitActionLabel(__('admin.actions.equip.modal_submit'))
            ->fillForm(function (BackpackItem $record): array {
                $slots = self::equippableSlotsFor($record);

                if ($slots === []) {
                    return [];
                }

                return ['slot' => $slots[0]->value];
            })
            ->schema(function (BackpackItem $record): array {
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
            ->action(function (BackpackItem $record, array $data, Component $livewire): void {
                $character = $record->character;

                if (! $character instanceof Character) {
                    $character = Character::query()->find($record->tg_id);
                }

                if (! $character instanceof Character) {
                    throw new RuntimeException('Character not found for backpack row.');
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

                $result = app(BackpackEquipToSlotAction::class)->handle($character, $record->id, $slot);

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

    private static function equipStatChangesHtml(BackpackItem $record, mixed $slotState): HtmlString
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

        return BackpackEquipPreviewHtml::format(
            app(LoadoutService::class)->equipStatChanges($character, $record, $slot),
        );
    }

    /**
     * @return list<SlotEnum>
     */
    private static function equippableSlotsFor(BackpackItem $record): array
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

                    return $query->whereHas('catalog',
                        fn (Builder $catalog): Builder => $catalog->where('profile', $value),
                    );
                }),
            TernaryFilter::make('has_socketed_gems')
                ->label(__('admin.labels.has_socketed_gems'))
                ->native(false)
                ->trueLabel(__('admin.labels.with_socketed_gems'))
                ->falseLabel(__('admin.labels.without_socketed_gems'))
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereHas('socketedGems'),
                    false: fn (Builder $query): Builder => $query->whereDoesntHave('socketedGems'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            TernaryFilter::make('has_gem_sockets')
                ->label(__('admin.labels.has_gem_sockets'))
                ->native(false)
                ->trueLabel(__('admin.labels.with_gem_sockets'))
                ->falseLabel(__('admin.labels.without_gem_sockets'))
                ->queries(
                    true: fn (Builder $query): Builder => $query
                        ->where('item_type', '!=', TypeEnum::JEWELRY->value)
                        ->whereHas('catalog',
                            fn (Builder $catalog): Builder => $catalog->where('gem_slots', '>', 0),
                        ),
                    false: fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('item_type', TypeEnum::JEWELRY->value)
                            ->orWhereDoesntHave('catalog')
                            ->orWhereHas('catalog',
                                fn (Builder $catalog): Builder => $catalog->where(function (Builder $slots): void {
                                    $slots->whereNull('gem_slots')
                                        ->orWhere('gem_slots', '<=', 0);
                                }),
                            );
                    }),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ];
    }
}
