<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Filament\Resources\Inventories\InventoryResource;
use App\Filament\Resources\Inventories\Tables\InventoriesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;

final class InventoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    protected static ?string $relatedResource = InventoryResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.inventories');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
                Section::make(self::getTitle($this->getOwnerRecord(), $this->getPageClass()))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->collapsed()
                    ->schema([
                        EmbeddedTable::make(),
                    ]),
                RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
            ]);
    }

    public function table(Table $table): Table
    {
        return InventoriesTable::configureForCharacter($table);
    }

    protected function getTableHeading(): string
    {
        return '';
    }
}
