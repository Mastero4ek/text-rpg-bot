<?php

declare(strict_types=1);

namespace App\Filament\Resources\Appeals\Tables;

use App\Enums\Appeal\AppealStatusEnum;
use App\Filament\Resources\Appeals\AppealResource;
use App\Filament\Support\CharacterSkinArt;
use App\Filament\Tables\Columns\AppearanceImageColumn;
use App\Models\Appeal\Appeal;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AppealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                AppearanceImageColumn::make('character.image')
                    ->label(__('admin.labels.appearance'))
                    ->collection('image')
                    ->circular()
                    ->imageSize(40)
                    ->alignCenter()
                    ->sortable(false)
                    ->defaultImageUrl(function (Appeal $record): ?string {
                        return CharacterSkinArt::url($record->character?->skin);
                    }),
                TextColumn::make('character.username')
                    ->label(__('admin.labels.username'))
                    ->searchable(['character.username', 'body', 'tg_id'])
                    ->tooltip(fn (Appeal $record): string => self::usernameOrTg($record))
                    ->limit(40)
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.labels.appeal_status'))
                    ->badge()
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.labels.created_at'))
                    ->dateTime('d.m.Y')
                    ->sinceTooltip()
                    ->alignCenter()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.labels.appeal_status'))
                    ->options(AppealStatusEnum::class)
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
                    ->modalHeading(fn (Appeal $record): string => __('admin.actions.delete.modal_heading', [
                        'label' => AppealResource::getRecordTitle($record) ?? (string) $record->id,
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.delete.modal_submit'))
                    ->successNotificationTitle(__('admin.actions.delete.notification')),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['character.media']))
            ->emptyStateHeading(__('admin.empty.appeals.heading'))
            ->emptyStateDescription(__('admin.empty.appeals.description'));
    }

    private static function usernameOrTg(Appeal $record): string
    {
        $username = $record->character?->username;

        if (is_string($username) && $username !== '') {
            return $username;
        }

        return (string) $record->tg_id;
    }
}
