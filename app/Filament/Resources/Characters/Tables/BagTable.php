<?php

declare(strict_types=1);

namespace App\Filament\Resources\Characters\Tables;

use App\Actions\Bag\BagGemDiscardAction;
use App\Actions\Bag\BagGemSocketAction;
use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Resources\BagCatalog\BagCatalogResource;
use App\Filament\Resources\Characters\RelationManagers\BackpackRelationManager;
use App\Filament\Resources\Characters\RelationManagers\BagRelationManager;
use App\Filament\Resources\Characters\RelationManagers\LoadoutRelationManager;
use App\Filament\Support\BackpackEquipPreviewHtml;
use App\Models\Backpack\BackpackItem;
use App\Models\Bag\BagCatalog;
use App\Models\Character;
use App\Services\Backpack\LoadoutService;
use App\Services\Bag\BagService;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use RuntimeException;

final class BagTable
{
    public static function configure(Table $table, Character $character, bool $canMutate): Table
    {
        $recordActions = [
            Action::make('view')
                ->icon('heroicon-o-eye')
                ->color(Color::Teal)
                ->label('')
                ->tooltip(__('admin.actions.view.label'))
                ->url(fn (array $record): ?string => self::catalogUrl($record))
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

                $kindFilter = $filters['kind']['value'] ?? null;

                if (is_string($kindFilter) && $kindFilter !== '') {
                    $filtered = [];

                    foreach ($rows as $row) {
                        if ($row['kind']->value === $kindFilter) {
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
                        $catalogId = mb_strtolower($row['catalog_id']);

                        if (str_contains($name, $needle) || str_contains($catalogId, $needle)) {
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
                    $keyed[(string) $item['id']] = $item;
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
                TextColumn::make('kind')
                    ->label(__('admin.labels.item_type'))
                    ->badge()
                    ->state(fn (array $record): BagKindEnum => $record['kind'])
                    ->alignCenter()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('profile')
                    ->label(__('admin.labels.profile'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('admin.labels.quantity'))
                    ->alignCenter()
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
                SelectFilter::make('kind')
                    ->label(__('admin.labels.item_type'))
                    ->native(false)
                    ->options(BagKindEnum::class),
            ])
            ->recordActions($recordActions)
            ->recordUrl(fn (array $record): ?string => self::catalogUrl($record))
            ->toolbarActions([])
            ->headerActions([])
            ->emptyStateHeading(__('admin.empty.bag.heading'))
            ->emptyStateDescription(__('admin.empty.bag.description'))
            ->searchable();
    }

    /**
     * @return list<array{
     *     id: int,
     *     catalog_id: string,
     *     name: string,
     *     kind: BagKindEnum,
     *     profile: GemTypeEnum|ProfileEnum|null,
     *     quantity: int,
     *     obtained_at: CarbonInterface|null,
     *     image_url: string|null,
     *     in_catalog: bool
     * }>
     */
    public static function rowsFor(Character $character): array
    {
        $bag = app(BagService::class);
        $items = [];

        foreach ($bag->looseGems($character) as $item) {
            $items[] = $item;
        }

        foreach ($bag->loosePotions($character) as $item) {
            $items[] = $item;
        }

        if ($items === []) {
            return [];
        }

        $catalogIds = [];

        foreach ($items as $item) {
            $catalogIds[] = $item->catalog_id;
        }

        $catalog = BagCatalog::query()
            ->withTrashed()
            ->with('media')
            ->whereIn('catalog_id', $catalogIds)
            ->get()
            ->keyBy('catalog_id');

        $rows = [];

        foreach ($items as $item) {
            $entry = $catalog->get($item->catalog_id);
            $obtainedAt = $item->created_at;

            if ($entry instanceof BagCatalog) {
                $url = $entry->getFirstMediaUrl('image');

                if ($url === '') {
                    $imageUrl = null;
                } else {
                    $imageUrl = self::relativeUrl($url);
                }

                $rows[] = [
                    'id' => $item->id,
                    'catalog_id' => $item->catalog_id,
                    'name' => $entry->name,
                    'kind' => $item->kind,
                    'profile' => self::catalogProfile($entry),
                    'quantity' => $item->quantity,
                    'obtained_at' => $obtainedAt,
                    'image_url' => $imageUrl,
                    'in_catalog' => true,
                ];

                continue;
            }

            $rows[] = [
                'id' => $item->id,
                'catalog_id' => $item->catalog_id,
                'name' => $item->catalog_id,
                'kind' => $item->kind,
                'profile' => null,
                'quantity' => $item->quantity,
                'obtained_at' => $obtainedAt,
                'image_url' => null,
                'in_catalog' => false,
            ];
        }

        return $rows;
    }

    private static function catalogProfile(BagCatalog $entry): GemTypeEnum|ProfileEnum|null
    {
        if ($entry->kind === BagKindEnum::GEM) {
            return $entry->type;
        }

        return $entry->profile;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function catalogUrl(array $record): ?string
    {
        if (! array_key_exists('in_catalog', $record) || ! $record['in_catalog']) {
            return null;
        }

        if (! array_key_exists('catalog_id', $record) || ! is_string($record['catalog_id'])) {
            return null;
        }

        if (! array_key_exists('kind', $record) || ! $record['kind'] instanceof BagKindEnum) {
            return null;
        }

        return BagCatalogResource::getUrl('view', ['record' => $record['catalog_id']]);
    }

    private static function discardAction(Character $character): Action
    {
        return Action::make('discard')
            ->icon('heroicon-o-trash')
            ->color(Color::Red)
            ->label('')
            ->tooltip(fn (array $record): string => self::discardCopy($record['kind'], 'label'))
            ->requiresConfirmation()
            ->modalHeading(fn (array $record): string => self::discardCopy($record['kind'], 'modal_heading', [
                'name' => $record['name'],
            ]))
            ->modalDescription(fn (array $record): string => self::discardCopy($record['kind'], 'modal_description'))
            ->modalSubmitActionLabel(fn (array $record): string => self::discardCopy($record['kind'], 'modal_submit'))
            ->action(function (array $record, Component $livewire) use ($character): void {
                $owner = self::ownerFromLivewire($livewire, $character);
                $result = app(BagGemDiscardAction::class)->handle($owner, $record['id']);

                if (! $result->ok) {
                    Notification::make()
                        ->title((string) $result->error)
                        ->danger()
                        ->send();

                    return;
                }

                self::refreshAfterMutation($livewire, $result->character);

                Notification::make()
                    ->title(self::discardCopy($record['kind'], 'notification'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    private static function discardCopy(BagKindEnum $kind, string $key, array $replace = []): string
    {
        if ($kind === BagKindEnum::POTION) {
            $group = 'admin.actions.discard_potion.';
        } else {
            $group = 'admin.actions.discard_gem.';
        }

        $text = __($group . $key, $replace);

        if (! is_string($text)) {
            throw new RuntimeException($group . $key . ' must be a string.');
        }

        return $text;
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
            ->visible(fn (array $record): bool => $record['kind'] === BagKindEnum::GEM)
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

                return ['backpack_item_id' => array_key_first($targets)];
            })
            ->schema(function (array $record) use ($character): array {
                $targets = self::socketTargets($character);

                if ($targets === []) {
                    return [];
                }

                return [
                    Select::make('backpack_item_id')
                        ->label(__('admin.actions.socket_gem.target_label'))
                        ->options($targets)
                        ->required()
                        ->live()
                        ->native(false),
                    Placeholder::make('socket_stat_changes')
                        ->hiddenLabel()
                        ->content(function (Get $get) use ($character, $record): HtmlString {
                            return self::socketStatChangesHtml($character, $record['catalog_id'], $get('backpack_item_id'));
                        }),
                ];
            })
            ->action(function (array $record, array $data, Component $livewire) use ($character): void {
                if (! array_key_exists('backpack_item_id', $data)) {
                    Notification::make()
                        ->title(__('admin.actions.socket_gem.no_targets'))
                        ->danger()
                        ->send();

                    return;
                }

                $owner = self::ownerFromLivewire($livewire, $character);
                $result = app(BagGemSocketAction::class)->handle(
                    $owner,
                    (int) $data['backpack_item_id'],
                    $record['id'],
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

    private static function socketStatChangesHtml(Character $character, string $catalogId, mixed $hostId): HtmlString
    {
        if (is_int($hostId)) {
            $backpackItemId = $hostId;
        } elseif (is_string($hostId) && $hostId !== '') {
            $backpackItemId = (int) $hostId;
        } else {
            return new HtmlString('');
        }

        if ($backpackItemId < 1) {
            return new HtmlString('');
        }

        $host = BackpackItem::query()
            ->where('tg_id', $character->tg_id)
            ->where('id', $backpackItemId)
            ->first();

        if (! $host instanceof BackpackItem) {
            return new HtmlString(e(__('errors.item_not_found')));
        }

        if ($catalogId === '') {
            throw new RuntimeException('Gem catalog id is required.');
        }

        return BackpackEquipPreviewHtml::format(
            app(LoadoutService::class)->socketStatChanges($character, $host, $catalogId),
        );
    }

    /**
     * @return array<int, string>
     */
    private static function socketTargets(Character $character): array
    {
        $bag = app(BagService::class);
        $rows = BackpackItem::query()
            ->where('tg_id', $character->tg_id)
            ->orderBy('id')
            ->get();

        $options = [];

        foreach ($rows as $row) {
            $free = $bag->freeSocketCount($row);

            if ($free <= 0) {
                continue;
            }

            $total = $bag->gemSlotCount($row);

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
     *     id: int,
     *     catalog_id: string,
     *     name: string,
     *     kind: BagKindEnum,
     *     profile: GemTypeEnum|ProfileEnum|null,
     *     quantity: int,
     *     obtained_at: CarbonInterface|null,
     *     image_url: string|null,
     *     in_catalog: bool
     * }  $row
     */
    private static function sortValue(array $row, string $column): string|int
    {
        if ($column === 'name') {
            return mb_strtolower($row['name']);
        }

        if ($column === 'kind') {
            return $row['kind']->value;
        }

        if ($column === 'profile') {
            if ($row['profile'] instanceof GemTypeEnum || $row['profile'] instanceof ProfileEnum) {
                return $row['profile']->value;
            }

            return '';
        }

        if ($column === 'obtained_at') {
            if ($row['obtained_at'] instanceof CarbonInterface) {
                return $row['obtained_at']->getTimestamp();
            }

            return 0;
        }

        return $row['id'];
    }
}
