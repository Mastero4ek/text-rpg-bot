<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Actions\Gem\GemDiscardFromPouchAction;
use App\Actions\Gem\GemSocketAction;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Filament\Resources\Gems\GemResource;
use App\Models\Character;
use App\Models\Gem;
use App\Models\Inventory;
use App\Services\Gem\GemService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Component;

final class GemPouchTable
{
    public static function configure(Table $table, Character $character, bool $canMutate): Table
    {
        $recordActions = [
            Action::make('view')
                ->icon('heroicon-o-eye')
                ->color(Color::Teal)
                ->label('')
                ->tooltip(__('admin.actions.view.label'))
                ->url(fn (array $record): string => GemResource::getUrl('view', [
                    'record' => $record['gem_id'],
                ]))
                ->visible(fn (array $record): bool => $record['in_catalog']),
        ];

        if ($canMutate) {
            $recordActions[] = self::socketAction($character);
            $recordActions[] = self::discardAction($character);
        }

        return $table
            ->records(function (
                ?string $search,
                array $filters,
                ?string $sortColumn,
                ?string $sortDirection,
                int|string $page,
                int|string $recordsPerPage,
            ) use ($character): LengthAwarePaginator {
                $rows = self::rowsFor($character);

                $typeFilter = $filters['type']['value'] ?? null;

                if (is_string($typeFilter) && $typeFilter !== '') {
                    $filtered = [];

                    foreach ($rows as $row) {
                        if ($row['type'] instanceof GemTypeEnum && $row['type']->value === $typeFilter) {
                            $filtered[] = $row;
                        }
                    }

                    $rows = $filtered;
                }

                if (is_string($search) && $search !== '') {
                    $needle = mb_strtolower($search);
                    $filtered = [];

                    foreach ($rows as $row) {
                        $name = mb_strtolower($row['name']);
                        $gemId = mb_strtolower($row['gem_id']);

                        if (str_contains($name, $needle) || str_contains($gemId, $needle)) {
                            $filtered[] = $row;
                        }
                    }

                    $rows = $filtered;
                }

                if (is_string($sortColumn) && $sortColumn !== '') {
                    $direction = $sortDirection === 'desc' ? 'desc' : 'asc';
                    usort($rows, function (array $left, array $right) use ($sortColumn, $direction): int {
                        $leftValue = self::sortValue($left, $sortColumn);
                        $rightValue = self::sortValue($right, $sortColumn);

                        if ($leftValue === $rightValue) {
                            return 0;
                        }

                        if ($direction === 'desc') {
                            return $leftValue < $rightValue ? 1 : -1;
                        }

                        return $leftValue < $rightValue ? -1 : 1;
                    });
                }

                $total = count($rows);

                if ($recordsPerPage === 'all') {
                    $perPage = max($total, 1);
                    $currentPage = 1;
                    $items = $rows;
                } else {
                    $perPage = (int) $recordsPerPage;

                    if ($perPage < 1) {
                        $perPage = 10;
                    }

                    $currentPage = (int) $page;

                    if ($currentPage < 1) {
                        $currentPage = 1;
                    }

                    $items = array_slice($rows, ($currentPage - 1) * $perPage, $perPage);
                }

                $keyed = [];

                foreach ($items as $item) {
                    $keyed[(string) $item['index']] = $item;
                }

                return new LengthAwarePaginator(
                    items: $keyed,
                    total: $total,
                    perPage: $perPage,
                    currentPage: $currentPage,
                );
            })
            ->columns([
                ViewColumn::make('image_url')
                    ->label(__('admin.labels.appearance'))
                    ->alignCenter()
                    ->view('filament.tables.columns.appearance-url'),
                TextColumn::make('name')
                    ->label(__('admin.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn (array $record): string => $record['name'])
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label(__('admin.labels.item_type'))
                    ->badge()
                    ->alignCenter()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('obtained_at')
                    ->label(__('admin.labels.obtained_at'))
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.labels.item_type'))
                    ->native(false)
                    ->options(GemTypeEnum::class),
            ])
            ->recordActions($recordActions)
            ->recordUrl(fn (array $record): ?string => $record['in_catalog']
                ? GemResource::getUrl('view', [
                    'record' => $record['gem_id'],
                ])
                : null)
            ->toolbarActions([])
            ->headerActions([])
            ->emptyStateHeading(__('admin.empty.bag.heading'))
            ->emptyStateDescription(__('admin.empty.bag.description'))
            ->searchable();
    }

    /**
     * @return list<array{
     *     index: int,
     *     gem_id: string,
     *     name: string,
     *     type: GemTypeEnum|null,
     *     obtained_at: Carbon|null,
     *     image_url: string|null,
     *     in_catalog: bool
     * }>
     */
    public static function rowsFor(Character $character): array
    {
        $pouch = app(GemService::class)->pouch($character);

        if ($pouch === []) {
            return [];
        }

        $gemIds = [];

        foreach ($pouch as $instance) {
            $gemIds[] = $instance['gem_id'];
        }

        $gems = Gem::query()
            ->withTrashed()
            ->with('media')
            ->whereIn('gem_id', $gemIds)
            ->get()
            ->keyBy('gem_id');

        $rows = [];

        foreach ($pouch as $index => $instance) {
            $gemId = $instance['gem_id'];
            $gem = $gems->get($gemId);
            $obtainedAt = self::obtainedAtFromInstance($instance);

            if ($gem instanceof Gem) {
                $url = $gem->getFirstMediaUrl('image');

                if ($url === '') {
                    $imageUrl = null;
                } else {
                    $imageUrl = self::relativeUrl($url);
                }

                $rows[] = [
                    'index' => $index,
                    'gem_id' => $gemId,
                    'name' => $gem->name,
                    'type' => $gem->type,
                    'obtained_at' => $obtainedAt,
                    'image_url' => $imageUrl,
                    'in_catalog' => true,
                ];

                continue;
            }

            $rows[] = [
                'index' => $index,
                'gem_id' => $gemId,
                'name' => $gemId,
                'type' => null,
                'obtained_at' => $obtainedAt,
                'image_url' => null,
                'in_catalog' => false,
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
            ->tooltip(__('admin.actions.discard_gem.label'))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => __('admin.actions.discard_gem.modal_heading', [
                'name' => $record['name'],
            ]))
            ->modalDescription(__('admin.actions.discard_gem.modal_description'))
            ->modalSubmitActionLabel(__('admin.actions.discard_gem.modal_submit'))
            ->action(function (array $record, Component $livewire) use ($character): void {
                $owner = self::ownerFromLivewire($livewire, $character);
                $result = app(GemDiscardFromPouchAction::class)->handle($owner, $record['index']);

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                self::refreshAfterMutation($livewire, $result->character);

                Notification::make()
                    ->title(__('admin.actions.discard_gem.notification'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array{gem_id: string, durability: int, added_at?: mixed}  $instance
     */
    private static function obtainedAtFromInstance(array $instance): ?Carbon
    {
        if (! array_key_exists('added_at', $instance)) {
            return null;
        }

        $raw = $instance['added_at'];

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return \Illuminate\Support\Facades\Date::parse($raw);
    }

    private static function ownerFromLivewire(Component $livewire, Character $character): Character
    {
        if ($livewire instanceof BagRelationManager) {
            $ownerRecord = $livewire->getOwnerRecord();

            if ($ownerRecord instanceof Character) {
                return $ownerRecord;
            }
        }

        return $character;
    }

    private static function refreshAfterMutation(Component $livewire, ?Character $character): void
    {
        if (! $livewire instanceof BagRelationManager) {
            return;
        }

        if ($character instanceof Character) {
            $livewire->ownerRecord = $character;
        }

        $livewire->resetTable();
        $livewire->dispatch('character-gems-changed')->to(BackpackRelationManager::class);
        $livewire->dispatch('character-gems-changed')->to(LoadoutRelationManager::class);
    }

    private static function relativeUrl(string $url): string
    {
        $appUrl = mb_rtrim((string) config('app.url'), '/');

        if (str_starts_with($url, $appUrl . '/')) {
            return mb_substr($url, mb_strlen($appUrl));
        }

        return $url;
    }

    private static function socketAction(Character $character): Action
    {
        return Action::make('socket')
            ->icon('heroicon-o-plus-circle')
            ->color(Color::Green)
            ->label('')
            ->tooltip(__('admin.actions.socket_gem.label'))
            ->requiresConfirmation()
            ->modalHeading(__('admin.actions.socket_gem.modal_heading'))
            ->modalDescription(function (array $record) use ($character): string {
                if (self::socketTargets($character) === []) {
                    return __('admin.actions.socket_gem.no_targets');
                }

                return __('admin.actions.socket_gem.modal_description', [
                    'name' => $record['name'],
                ]);
            })
            ->modalSubmitAction(function (Action $action) use ($character): Action|false {
                if (self::socketTargets($character) === []) {
                    return false;
                }

                return $action;
            })
            ->modalSubmitActionLabel(__('admin.actions.socket_gem.modal_submit'))
            ->fillForm(function () use ($character): array {
                $targets = self::socketTargets($character);

                if ($targets === []) {
                    return [];
                }

                return ['inventory_id' => array_key_first($targets)];
            })
            ->schema(function () use ($character): array {
                $targets = self::socketTargets($character);

                if ($targets === []) {
                    return [];
                }

                return [
                    Select::make('inventory_id')
                        ->label(__('admin.actions.socket_gem.target_label'))
                        ->options($targets)
                        ->required()
                        ->native(false),
                ];
            })
            ->action(function (array $record, array $data, Component $livewire) use ($character): void {
                if (! array_key_exists('inventory_id', $data)) {
                    Notification::make()
                        ->title(__('admin.actions.socket_gem.no_targets'))
                        ->danger()
                        ->send();

                    return;
                }

                $owner = self::ownerFromLivewire($livewire, $character);
                $result = app(GemSocketAction::class)->handle(
                    $owner,
                    (int) $data['inventory_id'],
                    $record['index'],
                );

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                self::refreshAfterMutation($livewire, $result->character);

                Notification::make()
                    ->title(__('admin.actions.socket_gem.notification'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, string>
     */
    private static function socketTargets(Character $character): array
    {
        $gems = app(GemService::class);
        $rows = Inventory::query()
            ->where('tg_id', $character->tg_id)
            ->orderBy('id')
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $free = $gems->freeSocketCount($row);

            if ($free <= 0) {
                continue;
            }

            $total = $gems->gemSlotCount($row);

            $options[$row->id] = __('admin.actions.socket_gem.item_option', [
                'name' => $row->item_name,
                'used' => $total - $free,
                'total' => $total,
            ]);
        }

        return $options;
    }

    /**
     * @param  array{
     *     index: int,
     *     gem_id: string,
     *     name: string,
     *     type: GemTypeEnum|null,
     *     obtained_at: Carbon|null,
     *     image_url: string|null,
     *     in_catalog: bool
     * }  $row
     */
    private static function sortValue(array $row, string $column): string|int
    {
        if ($column === 'name') {
            return mb_strtolower($row['name']);
        }

        if ($column === 'type') {
            if ($row['type'] instanceof GemTypeEnum) {
                return $row['type']->value;
            }

            return '';
        }

        if ($column === 'obtained_at') {
            if ($row['obtained_at'] instanceof Carbon) {
                return $row['obtained_at']->timestamp;
            }

            return 0;
        }

        return $row['index'];
    }
}
