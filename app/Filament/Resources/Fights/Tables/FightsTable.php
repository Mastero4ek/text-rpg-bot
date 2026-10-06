<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Tables;

use App\Actions\Fight\FightClearAction;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Fight\FightStepEnum;
use App\Filament\Resources\Fights\FightResource;
use App\Models\Fight;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class FightsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('tg_id')
            ->columns([
                TextColumn::make('character.username')
                    ->label(__('admin.labels.username'))
                    ->searchable()
                    ->tooltip(fn (Fight $record): string => self::enemyOrUsername($record))
                    ->limit(40)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('enemy_name')
                    ->label(__('admin.labels.enemy'))
                    ->state(fn (Fight $record): string => self::enemyName($record))
                    ->limit(40)
                    ->sortable()
                    ->tooltip(fn (Fight $record): string => self::enemyName($record))
                    ->placeholder('-'),
                TextColumn::make('kind')
                    ->label(__('admin.labels.fight_kind'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('step')
                    ->label(__('admin.labels.fight_step'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('turn_deadline_at')
                    ->label(__('admin.labels.turn_deadline_at'))
                    ->dateTime('d.m.Y H:i:s')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('admin.labels.fight_kind'))
                    ->options(FightKindEnum::class)
                    ->native(false),
                SelectFilter::make('step')
                    ->label(__('admin.labels.fight_step'))
                    ->options(FightStepEnum::class)
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make()
                    ->icon('heroicon-o-eye')
                    ->color(Color::Teal)
                    ->label('')
                    ->tooltip(__('admin.actions.view.label')),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->color(Color::Red)
                    ->label('')
                    ->tooltip(__('admin.actions.delete.label'))
                    ->modalHeading(fn (Fight $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => FightResource::getRecordTitle($record) ?? (string) $record->tg_id,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification'))
                    ->using(function (Fight $record): bool {
                        app(FightClearAction::class)->handle($record->tg_id);

                        return true;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label(__('admin.actions.delete_bulk.label'))
                        ->modalHeading(__('admin.actions.delete_bulk.modal_heading'))
                        ->modalSubmitActionLabel(__('admin.actions.delete_bulk.modal_submit'))
                        ->successNotificationTitle(__('admin.actions.delete_bulk.notification'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color(Color::Red)
                        ->using(function (Collection $records): void {
                            $clear = app(FightClearAction::class);

                            foreach ($records as $record) {
                                if (! $record instanceof Fight) {
                                    continue;
                                }

                                $clear->handle($record->tg_id);
                            }
                        }),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['character']))
            ->emptyStateHeading(__('admin.empty.fights.heading'))
            ->emptyStateDescription(__('admin.empty.fights.description'));
    }

    private static function enemyName(Fight $record): string
    {
        if (! array_key_exists('name', $record->enemy) || ! is_string($record->enemy['name']) || $record->enemy['name'] === '') {
            return '-';
        }

        return $record->enemy['name'];
    }

    private static function enemyOrUsername(Fight $record): string
    {
        $username = $record->character?->username;

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) $record->tg_id;
    }
}
