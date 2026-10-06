<?php

declare(strict_types=1);

namespace App\Filament\Resources\CityCatalog\RelationManagers;

use App\Actions\City\CitySetCharactersMaxRowsAction;
use App\Filament\Resources\CityCatalog\Tables\CityCharactersTable;
use App\Models\City;
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

final class CharactersRelationManager extends RelationManager
{
    public ?int $charactersMaxRows = null;

    protected static string $relationship = 'characters';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.characters');
    }

    public static function tableCapacityToolbar(): Htmlable
    {
        $component = Livewire::current();

        if (! $component instanceof self) {
            return new HtmlString('');
        }

        $owner = $component->getOwnerRecord();

        if (! $owner instanceof City) {
            return new HtmlString('');
        }

        if ($component->charactersMaxRows === null) {
            $component->charactersMaxRows = $owner->characters_max_rows;
        }

        $current = $owner->characters()->count();
        $editable = ! $component->isReadOnly();

        return new HtmlString(
            view('filament.characters.capacity-toolbar', [
                'current' => $current,
                'max' => $component->charactersMaxRows ?? $owner->characters_max_rows,
                'editable' => $editable,
                'filledLabel' => __('admin.labels.city_characters_filled'),
                'ofLabel' => __('admin.labels.city_characters_capacity_of'),
                'capacityLabel' => __('admin.labels.city_characters_capacity'),
                'maxWireModel' => 'charactersMaxRows',
            ])->render()
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                Section::make(__('admin.navigation.characters'))
                    ->icon(Heroicon::OutlinedUserGroup)
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

        if ($owner instanceof City) {
            $this->charactersMaxRows = $owner->characters_max_rows;
        }
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof City) {
            return $table
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw('0 = 1'))
                ->paginated(false)
                ->columns([])
                ->headerActions([])
                ->toolbarActions([])
                ->recordActions([]);
        }

        return CityCharactersTable::configure($table, ! $this->isReadOnly())
            ->recordAction(null);
    }

    public function updatedCharactersMaxRows(mixed $value): void
    {
        if ($this->isReadOnly()) {
            return;
        }

        $owner = $this->getOwnerRecord();

        if (! $owner instanceof City) {
            return;
        }

        if (! is_numeric($value)) {
            $this->charactersMaxRows = $owner->characters_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_city_characters_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        $maxRows = (int) $value;

        if ($maxRows < 1) {
            $this->charactersMaxRows = $owner->characters_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_city_characters_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        try {
            $updated = app(CitySetCharactersMaxRowsAction::class)->handle($owner, $maxRows);
        } catch (InvalidArgumentException) {
            $this->charactersMaxRows = $owner->characters_max_rows;
            Notification::make()
                ->title(__('admin.actions.set_city_characters_max_rows.invalid'))
                ->danger()
                ->send();

            return;
        }

        $this->ownerRecord = $updated;
        $this->charactersMaxRows = $updated->characters_max_rows;

        Notification::make()
            ->title(__('admin.actions.set_city_characters_max_rows.notification'))
            ->success()
            ->send();
    }

    protected function getTableHeading(): string
    {
        return '';
    }
}
