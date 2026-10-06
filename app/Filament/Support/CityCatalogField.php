<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\City;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

final class CityCatalogField
{
    public static function make(): Select
    {
        return Select::make('cities')
            ->label(__('admin.labels.cities'))
            ->relationship(
                'cities',
                'name',
                function (Builder $query): Builder {
                    return $query->where('enabled', true)->orderBy('name')->orderBy('id');
                },
            )
            ->multiple()
            ->preload()
            ->searchable()
            ->native(false)
            ->hintAction(
                Action::make('all_cities')
                    ->label(__('admin.actions.all_cities.label'))
                    ->visible(fn (string $operation): bool => $operation !== 'view')
                    ->action(function (Set $set): void {
                        $ids = [];

                        foreach (City::query()->where('enabled', true)->orderBy('id')->get() as $city) {
                            $ids[] = $city->id;
                        }

                        $set('cities', $ids);
                    }),
            );
    }
}
