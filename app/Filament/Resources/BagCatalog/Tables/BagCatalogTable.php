<?php

declare(strict_types=1);

namespace App\Filament\Resources\BagCatalog\Tables;

use App\Enums\Bag\BagKindEnum;
use App\Enums\Equipment\ProfileEnum;
use App\Enums\Gem\GemTypeEnum;
use App\Filament\Concerns\HasAppearanceColumn;
use App\Models\Bag\BagCatalog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class BagCatalogTable
{
    use HasAppearanceColumn;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                self::appearanceColumn(),
                TextColumn::make('name')
                    ->label(__('admin.labels.name'))
                    ->searchable(['name', 'catalog_id'])
                    ->tooltip(fn (BagCatalog $record): string => $record->name)
                    ->limit(40)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('kind')
                    ->label(__('admin.labels.bag_kind'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('profile_display')
                    ->label(__('admin.labels.profile'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->state(function (BagCatalog $record): GemTypeEnum|ProfileEnum|null {
                        return match ($record->kind) {
                            BagKindEnum::GEM => $record->type,
                            BagKindEnum::POTION => $record->profile,
                        };
                    }),
                IconColumn::make('in_shop')
                    ->label(__('admin.labels.in_shop'))
                    ->alignCenter()
                    ->sortable()
                    ->placeholder('-')
                    ->boolean(),
                IconColumn::make('enabled')
                    ->label(__('admin.labels.enabled'))
                    ->alignCenter()
                    ->sortable()
                    ->placeholder('-')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->label(__('admin.labels.created_at'))
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->label(__('admin.labels.updated_at'))
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('admin.labels.bag_kind'))
                    ->native(false)
                    ->options(BagKindEnum::class),
                SelectFilter::make('profile_display')
                    ->label(__('admin.labels.profile'))
                    ->native(false)
                    ->options(self::profileFilterOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $gemType = GemTypeEnum::tryFrom($value);

                        if ($gemType instanceof GemTypeEnum) {
                            return $query->where('type', $gemType);
                        }

                        $profile = ProfileEnum::tryFrom($value);

                        if ($profile instanceof ProfileEnum) {
                            return $query->where('profile', $profile);
                        }

                        return $query;
                    }),
                TernaryFilter::make('in_shop')
                    ->label(__('admin.labels.in_shop'))
                    ->native(false),
                TernaryFilter::make('enabled')
                    ->label(__('admin.labels.enabled'))
                    ->native(false),
                TrashedFilter::make()
                    ->label(__('admin.actions.trashed_filter.label'))
                    ->placeholder(__('admin.actions.trashed_filter.without'))
                    ->trueLabel(__('admin.actions.trashed_filter.with'))
                    ->falseLabel(__('admin.actions.trashed_filter.only'))
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make()
                    ->icon('heroicon-o-eye')
                    ->color(Color::Teal)
                    ->label('')
                    ->tooltip(__('admin.actions.view.label')),
                EditAction::make()
                    ->icon('heroicon-o-pencil')
                    ->color(Color::Sky)
                    ->label('')
                    ->tooltip(__('admin.actions.edit.label')),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color(Color::Amber)
                    ->label('')
                    ->tooltip(__('admin.actions.archive.label'))
                    ->modalHeading(fn (BagCatalog $record): string => __('admin.actions.archive.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.archive.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.archive.notification')),
                RestoreAction::make()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color(Color::Green)
                    ->label('')
                    ->tooltip(__('admin.actions.restore.label'))
                    ->modalHeading(fn (BagCatalog $record): string => __('admin.actions.restore.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.restore.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.restore.notification')),
                ForceDeleteAction::make()
                    ->icon('heroicon-o-trash')
                    ->color(Color::Red)
                    ->label('')
                    ->tooltip(__('admin.actions.delete.label'))
                    ->modalHeading(fn (BagCatalog $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => $record->name,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('admin.actions.archive_bulk.label'))
                        ->modalHeading(__('admin.actions.archive_bulk.modal_heading'))
                        ->modalSubmitActionLabel(__('admin.actions.archive_bulk.modal_submit'))
                        ->successNotificationTitle(__('admin.actions.archive_bulk.notification'))
                        ->icon(null)
                        ->color(Color::Amber),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('media'));
    }

    /**
     * @return array<string, string>
     */
    private static function profileFilterOptions(): array
    {
        $options = [];

        foreach (GemTypeEnum::cases() as $type) {
            $options[$type->value] = $type->getLabel();
        }

        foreach ([ProfileEnum::HEAL, ProfileEnum::STAMINA] as $profile) {
            $options[$profile->value] = $profile->getLabel();
        }

        return $options;
    }
}
