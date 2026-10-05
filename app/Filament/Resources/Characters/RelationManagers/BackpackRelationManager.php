<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Actions\Character\CharacterSetInventoryMaxRowsAction;
use App\Filament\Resources\Inventories\InventoryResource;
use App\Filament\Resources\Inventories\Tables\InventoriesTable;
use App\Models\Character;
use App\Services\Inventory\InventoryService;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Livewire\Livewire;

final class BackpackRelationManager extends RelationManager
{
    public ?int $inventoryMaxRows = null;

    protected static string $relationship = 'inventories';

    protected static ?string $relatedResource = InventoryResource::class;

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.backpack');
    }

    public static function tableCapacityToolbar(): Htmlable
    {
        $component = Livewire::current();

        if (! $component instanceof self) {
            return new HtmlString('');
        }

        $owner = $component->getOwnerRecord();

        if (! $owner instanceof Character) {
            return new HtmlString('');
        }

        if ($component->inventoryMaxRows === null) {
            $component->inventoryMaxRows = $owner->inventory_max_rows;
        }

        $current = app(InventoryService::class)->rowCount($owner->tg_id);
        $editable = ! $component->isReadOnly();

        return new HtmlString(
            view('filament.characters.backpack-capacity', [
                'current' => $current,
                'max' => $component->inventoryMaxRows ?? $owner->inventory_max_rows,
                'editable' => $editable,
            ])->render()
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                Section::make(__('admin.navigation.backpack'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->collapsed()
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    public function mount(): void
    {
        parent::mount();

        $owner = $this->getOwnerRecord();

        if ($owner instanceof Character) {
            $this->inventoryMaxRows = $owner->inventory_max_rows;
        }
    }

    public function table(Table $table): Table
    {
        if ($this->isReadOnly()) {
            return InventoriesTable::configureForCharacterView($table)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereDoesntHave('loadoutSlot'));
        }

        return InventoriesTable::configureForCharacter($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereDoesntHave('loadoutSlot'));
    }

    public function updatedInventoryMaxRows(mixed $value): void
    {
        if ($this->isReadOnly()) {
            return;
        }

        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Character) {
            return;
        }

        if (! is_numeric($value)) {
            $this->inventoryMaxRows = $owner->inventory_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_inventory_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        $maxRows = (int) $value;

        if ($maxRows < 1) {
            $this->inventoryMaxRows = $owner->inventory_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_inventory_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        try {
            $updated = app(CharacterSetInventoryMaxRowsAction::class)->handle($owner, $maxRows);
        } catch (InvalidArgumentException) {
            $this->inventoryMaxRows = $owner->inventory_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_inventory_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        $this->ownerRecord = $updated;
        $this->inventoryMaxRows = $updated->inventory_max_rows;

        Notification::make()
            ->title(__('admin.actions.set_inventory_max_rows.notification'))
            ->success()
            ->send();
    }

    protected function getTableHeading(): string
    {
        return '';
    }
}
