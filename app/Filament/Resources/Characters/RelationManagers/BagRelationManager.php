<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Actions\Character\CharacterSetBagMaxRowsAction;
use App\Filament\Resources\Characters\Tables\GemPouchTable;
use App\Models\Character;
use App\Services\Gem\GemService;
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
use Livewire\Attributes\On;
use Livewire\Livewire;

final class BagRelationManager extends RelationManager
{
    public ?int $bagMaxRows = null;

    protected static string $relationship = 'inventories';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.bag');
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

        if ($component->bagMaxRows === null) {
            $component->bagMaxRows = $owner->bag_max_rows;
        }

        $current = app(GemService::class)->bagRowCount($owner);
        $editable = ! $component->isReadOnly();

        return new HtmlString(
            view('filament.characters.capacity-toolbar', [
                'current' => $current,
                'max' => $component->bagMaxRows ?? $owner->bag_max_rows,
                'editable' => $editable,
                'filledLabel' => __('admin.labels.bag_filled'),
                'ofLabel' => __('admin.labels.bag_capacity_of'),
                'capacityLabel' => __('admin.labels.bag_capacity'),
                'maxWireModel' => 'bagMaxRows',
            ])->render()
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                Section::make(__('admin.navigation.bag'))
                    ->icon(Heroicon::OutlinedShoppingBag)
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
            $this->bagMaxRows = $owner->bag_max_rows;
        }
    }

    #[On('character-gems-changed')]
    public function refreshAfterExternalChange(): void
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof Character) {
            $owner->refresh();
            $this->ownerRecord = $owner;
            $this->bagMaxRows = $owner->bag_max_rows;
        }

        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Character) {
            return $table
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw('0 = 1'))
                ->paginated(false)
                ->columns([])
                ->headerActions([])
                ->toolbarActions([])
                ->recordActions([]);
        }

        return GemPouchTable::configure($table, $owner, ! $this->isReadOnly())
            ->recordAction(null);
    }

    public function updatedBagMaxRows(mixed $value): void
    {
        if ($this->isReadOnly()) {
            return;
        }

        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Character) {
            return;
        }

        if (! is_numeric($value)) {
            $this->bagMaxRows = $owner->bag_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_bag_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        $maxRows = (int) $value;

        if ($maxRows < 1) {
            $this->bagMaxRows = $owner->bag_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_bag_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        try {
            $updated = app(CharacterSetBagMaxRowsAction::class)->handle($owner, $maxRows);
        } catch (InvalidArgumentException) {
            $this->bagMaxRows = $owner->bag_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_bag_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        $this->ownerRecord = $updated;
        $this->bagMaxRows = $updated->bag_max_rows;

        Notification::make()
            ->title(__('admin.actions.set_bag_max_rows.notification'))
            ->success()
            ->send();
    }

    protected function getTableHeading(): string
    {
        return '';
    }
}
