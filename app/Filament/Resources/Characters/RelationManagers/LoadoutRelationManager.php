<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Filament\Resources\Characters\Tables\LoadoutTable;
use App\Models\Character;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

final class LoadoutRelationManager extends RelationManager
{
    protected static string $relationship = 'loadoutSlots';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.loadout');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                Section::make(__('admin.navigation.loadout'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->collapsed()
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    #[On('character-gems-changed')]
    #[On('character-loadout-changed')]
    public function refreshAfterExternalChange(): void
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof Character) {
            $owner->refresh();
            $this->ownerRecord = $owner;
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

        return LoadoutTable::configure($table, $owner, ! $this->isReadOnly())
            ->recordAction(null);
    }

    protected function getTableHeading(): string
    {
        return '';
    }
}
