<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Actions\Backpack\BackpackDiscardEquippedAction;
use App\Actions\Backpack\BackpackUnequipAction;
use App\Enums\Equipment\ProfileEnum;
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
use App\Filament\Support\GemSocketSlots;
use App\Models\Backpack\BackpackItem;
use App\Models\Character;
use App\Models\LoadoutSlot;
use App\Services\Backpack\LoadoutService;
use App\Services\Shop\ShopCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use RuntimeException;

final class LoadoutTable
{
    public static function configure(Table $table, Character $character, bool $canMutate): Table
    {
        $recordActions = [
            Action::make('view')
                ->icon('heroicon-o-eye')
                ->color(Color::Teal)
                ->label('')
                ->tooltip(__('admin.actions.view.label'))
                ->url(fn (array $record): string => BackpackCatalogResource::getUrl('view', [
                    'record' => $record['item_id'],
                ]))
                ->visible(fn (array $record): bool => is_string($record['item_id']) && $record['in_catalog']),
        ];

        if ($canMutate) {
            $recordActions[] = self::unequipAction($character);
            $recordActions[] = self::discardAction($character);
        }

        return $table
            ->records(fn (): array => self::keyedRowsFor($character))
            ->paginated(false)
            ->searchable(false)
            ->columns([
                ViewColumn::make('image_url')
                    ->label(__('admin.labels.appearance'))
                    ->alignCenter()
                    ->view('filament.tables.columns.loadout-appearance'),
                TextColumn::make('slot_label')
                    ->label(__('admin.labels.slot'))
                    ->placeholder('-'),
                TextColumn::make('item_name')
                    ->label(__('admin.labels.item_name'))
                    ->limit(40)
                    ->tooltip(fn (array $record): ?string => $record['item_name'])
                    ->placeholder('-'),
                TextColumn::make('item_type')
                    ->label(__('admin.labels.item_type'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-'),
                TextColumn::make('profile')
                    ->label(__('admin.labels.profile'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-'),
                ViewColumn::make('socket_slots')
                    ->label(__('admin.labels.socketed_gems'))
                    ->alignCenter()
                    ->view('filament.tables.columns.socket-slots'),
                TextColumn::make('durability')
                    ->label(__('admin.labels.durability_pair'))
                    ->alignCenter()
                    ->placeholder('-'),
            ])
            ->filters([])
            ->recordActions($recordActions)
            ->recordUrl(fn (array $record): ?string => is_string($record['item_id']) && $record['in_catalog']
                ? BackpackCatalogResource::getUrl('view', [
                    'record' => $record['item_id'],
                ])
                : null)
            ->toolbarActions([])
            ->headerActions([])
            ->emptyStateHeading(__('admin.empty.loadout.heading'))
            ->emptyStateDescription(__('admin.empty.loadout.description'));
    }

    /**
     * @return array<string, array{
     *     slot: string,
     *     slot_label: string,
     *     inventory_id: int|null,
     *     item_id: string|null,
     *     in_catalog: bool,
     *     equipped: bool,
     *     item_name: string|null,
     *     item_type: TypeEnum|null,
     *     profile: ProfileEnum|null,
     *     image_url: string|null,
     *     socket_slots: list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>,
     *     durability: string|null
     * }>
     */
    public static function keyedRowsFor(Character $character): array
    {
        $keyed = [];

        foreach (self::rowsFor($character) as $row) {
            $keyed[$row['slot']] = $row;
        }

        return $keyed;
    }

    /**
     * @return list<array{
     *     slot: string,
     *     slot_label: string,
     *     inventory_id: int|null,
     *     item_id: string|null,
     *     in_catalog: bool,
     *     equipped: bool,
     *     item_name: string|null,
     *     item_type: TypeEnum|null,
     *     profile: ProfileEnum|null,
     *     image_url: string|null,
     *     socket_slots: list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>,
     *     durability: string|null
     * }>
     */
    public static function rowsFor(Character $character): array
    {
        $bySlot = [];

        $slots = LoadoutSlot::query()
            ->where('tg_id', $character->tg_id)
            ->with(['backpackItem.catalog.media'])
            ->get();

        foreach ($slots as $loadoutSlot) {
            $bySlot[$loadoutSlot->slot->value] = $loadoutSlot->backpackItem;
        }

        $shop = app(ShopCatalog::class);
        $rows = [];

        foreach (SlotEnum::gameplayEquipSlots() as $slot) {
            $inventory = null;

            if (array_key_exists($slot->value, $bySlot) && $bySlot[$slot->value] instanceof BackpackItem) {
                $inventory = $bySlot[$slot->value];
            }

            if (! $inventory instanceof BackpackItem) {
                $rows[] = self::emptyRow($slot);

                continue;
            }

            $imageUrl = null;
            $profile = null;
            $inCatalog = $shop->hasItem($inventory->catalog_id);

            if ($inCatalog) {
                $profile = $shop->findItem($inventory->catalog_id)->profile;

                $equipment = $inventory->catalog;

                if ($equipment !== null) {
                    $url = $equipment->getFirstMediaUrl('image');

                    if ($url !== '') {
                        $imageUrl = self::relativeUrl($url);
                    }
                }
            }

            $rows[] = [
                'slot' => $slot->value,
                'slot_label' => $slot->getLabel(),
                'inventory_id' => $inventory->id,
                'item_id' => $inventory->catalog_id,
                'in_catalog' => $inCatalog,
                'equipped' => true,
                'item_name' => $inventory->item_name,
                'item_type' => $inventory->item_type,
                'profile' => $profile,
                'image_url' => $imageUrl,
                'socket_slots' => GemSocketSlots::forBackpackItem($inventory),
                'durability' => BackpackDurabilityText::format($inventory),
            ];
        }

        return $rows;
    }

    private static function discardAction(Character $character): Action
    {
        return Action::make('discard')
            ->icon('heroicon-o-trash')
            ->color(Color::Red)
            ->label('')
            ->tooltip(__('admin.actions.discard.label'))
            ->visible(fn (array $record): bool => $record['inventory_id'] !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('admin.actions.discard.modal_heading', [
                'name' => (string) $record['item_name'],
            ]))
            ->modalDescription(__('admin.actions.discard.modal_description'))
            ->modalSubmitActionLabel(__('admin.actions.discard.modal_submit'))
            ->action(function (array $record, Component $livewire) use ($character): void {
                $inventoryId = self::inventoryIdFromRecord($record);

                if ($inventoryId === null) {
                    throw new RuntimeException('Discard requires inventory id.');
                }

                $owner = self::ownerFromLivewire($livewire, $character);
                $result = app(BackpackDiscardEquippedAction::class)->handle($owner, $inventoryId);

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                self::refreshAfterMutation($livewire, $result->character);

                Notification::make()
                    ->title(__('admin.actions.discard.notification'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array{
     *     slot: string,
     *     slot_label: string,
     *     inventory_id: int|null,
     *     item_id: string|null,
     *     in_catalog: bool,
     *     equipped: bool,
     *     item_name: string|null,
     *     item_type: TypeEnum|null,
     *     profile: ProfileEnum|null,
     *     image_url: string|null,
     *     socket_slots: list<array{kind: 'image'|'camera'|'empty', url?: string, tooltip?: string}>,
     *     durability: string|null
     * }
     */
    private static function emptyRow(SlotEnum $slot): array
    {
        return [
            'slot' => $slot->value,
            'slot_label' => $slot->getLabel(),
            'inventory_id' => null,
            'item_id' => null,
            'in_catalog' => false,
            'equipped' => false,
            'item_name' => null,
            'item_type' => null,
            'profile' => null,
            'image_url' => null,
            'socket_slots' => [],
            'durability' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function inventoryIdFromRecord(array $record): ?int
    {
        if (! array_key_exists('inventory_id', $record)) {
            return null;
        }

        $inventoryId = $record['inventory_id'];

        if (! is_int($inventoryId)) {
            return null;
        }

        return $inventoryId;
    }

    private static function ownerFromLivewire(Component $livewire, Character $character): Character
    {
        if ($livewire instanceof LoadoutRelationManager) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord instanceof Character) {
                return $ownerRecord;
            }
        }

        return $character;
    }

    private static function refreshAfterMutation(Component $livewire, ?Character $character): void
    {
        if (! $livewire instanceof LoadoutRelationManager) {
            return;
        }

        if ($character instanceof Character) {
            $livewire->ownerRecord = $character;
        }

        $livewire->resetTable();
        $livewire->dispatch('character-loadout-changed')->to(BackpackRelationManager::class);
        $livewire->dispatch('character-gems-changed')->to(BagRelationManager::class);
        $livewire->dispatch('character-vitals-changed')->to(EditCharacter::class);
        $livewire->dispatch('character-vitals-changed')->to(ViewCharacter::class);
    }

    private static function relativeUrl(string $url): string
    {
        $appUrl = mb_rtrim((string) config('app.url'), '/');

        if (str_starts_with($url, $appUrl . '/')) {
            return mb_substr($url, mb_strlen($appUrl));
        }

        return $url;
    }

    private static function unequipAction(Character $character): Action
    {
        return Action::make('unequip')
            ->icon('heroicon-o-minus-circle')
            ->color(Color::Amber)
            ->label('')
            ->tooltip(__('admin.actions.unequip.label'))
            ->visible(fn (array $record): bool => $record['inventory_id'] !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('admin.actions.unequip.modal_heading', [
                'name' => (string) $record['item_name'],
            ]))
            ->modalDescription('')
            ->modalSubmitActionLabel(__('admin.actions.unequip.modal_submit'))
            ->schema(function (array $record) use ($character): array {
                $inventoryId = self::inventoryIdFromRecord($record);

                return [
                    Placeholder::make('unequip_stat_changes')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => self::unequipStatChangesHtml($character, $inventoryId)),
                ];
            })
            ->action(function (array $record, Component $livewire) use ($character): void {
                $inventoryId = self::inventoryIdFromRecord($record);

                if ($inventoryId === null) {
                    throw new RuntimeException('Unequip requires inventory id.');
                }

                $owner = self::ownerFromLivewire($livewire, $character);
                $result = app(BackpackUnequipAction::class)->handle($owner, $inventoryId);

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                self::refreshAfterMutation($livewire, $result->character);

                Notification::make()
                    ->title(__('admin.actions.unequip.notification'))
                    ->success()
                    ->send();
            });
    }

    private static function unequipStatChangesHtml(Character $character, ?int $inventoryId): HtmlString
    {
        if ($inventoryId === null) {
            return new HtmlString(e(__('errors.item_not_found')));
        }

        $inventory = BackpackItem::query()
            ->where('id', $inventoryId)
            ->where('tg_id', $character->tg_id)
            ->first();

        if (! $inventory instanceof BackpackItem) {
            return new HtmlString(e(__('errors.item_not_found')));
        }

        return BackpackEquipPreviewHtml::format(
            app(LoadoutService::class)->unequipStatChanges($character, $inventory),
        );
    }
}
