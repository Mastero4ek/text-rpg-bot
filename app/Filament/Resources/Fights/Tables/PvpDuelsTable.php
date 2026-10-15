<?php

declare(strict_types=1);

namespace App\Filament\Resources\Fights\Tables;

use App\Actions\Pvp\PvpDuelClearAction;
use App\Enums\Pvp\PvpDuelStatusEnum;
use App\Filament\Resources\Fights\PvpFightResource;
use App\Filament\Support\FightAppearanceSlots;
use App\Models\Pvp\PvpDuel;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class PvpDuelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                ViewColumn::make('appearance_slots')
                    ->label(__('admin.labels.appearance'))
                    ->alignCenter()
                    ->state(fn (PvpDuel $record): array => FightAppearanceSlots::forDuel($record))
                    ->view('filament.tables.columns.socket-slots'),
                TextColumn::make('sideA.username')
                    ->label(__('admin.labels.username'))
                    ->searchable()
                    ->limit(40)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('sideB.username')
                    ->label(__('admin.labels.enemy'))
                    ->searchable()
                    ->limit(40)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.labels.pvp_duel_status'))
                    ->badge()
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('round')
                    ->label(__('admin.labels.pvp_round'))
                    ->alignCenter()
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('turn_deadline_at')
                    ->label(__('admin.labels.round_deadline_at'))
                    ->state(fn (PvpDuel $record): mixed => self::turnDeadlineAt($record))
                    ->dateTime('d.m.Y H:i:s')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.labels.pvp_duel_status'))
                    ->options(PvpDuelStatusEnum::class)
                    ->native(false),
            ])
            ->recordActions([
                ViewAction::make()
                    ->icon('heroicon-o-eye')
                    ->color(Color::Teal)
                    ->label('')
                    ->tooltip(__('admin.actions.view.label'))
                    ->url(fn (PvpDuel $record): string => PvpFightResource::getUrl('view', ['record' => $record])),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->color(Color::Red)
                    ->label('')
                    ->tooltip(__('admin.actions.delete.label'))
                    ->modalHeading(fn (PvpDuel $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => PvpFightResource::getRecordTitle($record) ?? (string) $record->id,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification'))
                    ->using(function (PvpDuel $record): bool {
                        app(PvpDuelClearAction::class)->handle($record);

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
                            $clear = app(PvpDuelClearAction::class);

                            foreach ($records as $record) {
                                if (! $record instanceof PvpDuel) {
                                    continue;
                                }

                                $clear->handle($record);
                            }
                        }),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'sideA.media',
                'sideB.media',
                'fights',
            ]))
            ->emptyStateHeading(__('admin.empty.pvp_duels.heading'))
            ->emptyStateDescription(__('admin.empty.pvp_duels.description'));
    }

    private static function turnDeadlineAt(PvpDuel $duel): mixed
    {
        foreach ($duel->fights as $fight) {
            if ($fight->turn_deadline_at !== null) {
                return $fight->turn_deadline_at;
            }
        }

        return null;
    }
}
