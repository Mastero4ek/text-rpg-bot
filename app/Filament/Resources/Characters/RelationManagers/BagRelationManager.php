<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\RelationManagers;

use App\Filament\Resources\Characters\Tables\GemPouchTable;
use App\Models\Character;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class BagRelationManager extends RelationManager
{
    protected static string $relationship = 'inventories';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.bag');
    }

    public function content(Schema $schema): Schema
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Character) {
            return $schema->components([]);
        }

        return $schema
            ->components([
                Section::make(__('admin.navigation.bag'))
                    ->icon(Heroicon::OutlinedSparkles)
                    ->description(__('admin.hints.gem_pouch_ward', [
                        'ward' => $owner->gem_ward_charges,
                    ]))
                    ->collapsed()
                    ->schema([
                        View::make('filament.characters.gem-pouch')
                            ->viewData([
                                'rows' => GemPouchTable::rowsFor($owner),
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereRaw('0 = 1'))
            ->paginated(false)
            ->columns([])
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([]);
    }
}
