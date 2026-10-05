<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Filament\Resources\Characters\Tables\LoadoutTable;
use App\Models\Character;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Character) {
            return $schema->components([]);
        }

        return $schema
            ->components([
                Section::make(__('admin.navigation.loadout'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->collapsed()
                    ->schema([
                        View::make('filament.characters.loadout')
                            ->viewData([
                                'rows' => LoadoutTable::rowsFor($owner),
                            ]),
                    ]),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([]);
    }
}
