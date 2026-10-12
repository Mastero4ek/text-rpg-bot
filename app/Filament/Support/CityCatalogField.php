<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\City;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

final class CityCatalogField
{
    public static function make(): Select
    {
        return self::citiesSelect(
            'cities',
            __('admin.labels.cities'),
            'all_cities',
            __('admin.actions.all_cities.label'),
            function (Builder $query): Builder {
                return $query->where('enabled', true)->orderBy('name')->orderBy('id');
            },
        );
    }

    public static function makeForest(): Select
    {
        return self::citiesSelect(
            'cities',
            __('admin.labels.forest'),
            'all_forest_cities',
            __('admin.actions.all_forest_cities.label'),
            function (Builder $query): Builder {
                return $query
                    ->where('enabled', true)
                    ->where('has_forest', true)
                    ->orderBy('name')
                    ->orderBy('id');
            },
        );
    }

    public static function makePort(): Select
    {
        return self::citiesSelect(
            'portCities',
            __('admin.labels.port'),
            'all_port_cities',
            __('admin.actions.all_port_cities.label'),
            function (Builder $query): Builder {
                return $query
                    ->where('enabled', true)
                    ->where('has_port', true)
                    ->orderBy('name')
                    ->orderBy('id');
            },
        );
    }

    public static function makeRuins(): Select
    {
        return self::citiesSelect(
            'ruinsCities',
            __('admin.labels.ruins'),
            'all_ruins_cities',
            __('admin.actions.all_ruins_cities.label'),
            function (Builder $query): Builder {
                return $query
                    ->where('enabled', true)
                    ->where('has_ruins', true)
                    ->orderBy('name')
                    ->orderBy('id');
            },
        );
    }

    public static function makeSwamp(): Select
    {
        return self::citiesSelect(
            'swampCities',
            __('admin.labels.swamp'),
            'all_swamp_cities',
            __('admin.actions.all_swamp_cities.label'),
            function (Builder $query): Builder {
                return $query
                    ->where('enabled', true)
                    ->where('has_swamp', true)
                    ->orderBy('name')
                    ->orderBy('id');
            },
        );
    }

    public static function makeTraining(): Select
    {
        return self::citiesSelect(
            'trainingCities',
            __('admin.labels.training_hall'),
            'all_training_cities',
            __('admin.actions.all_training_cities.label'),
            function (Builder $query): Builder {
                return $query
                    ->where('enabled', true)
                    ->where('has_training_room', true)
                    ->orderBy('name')
                    ->orderBy('id');
            },
        );
    }

    /**
     * @param  Closure(Builder): Builder  $scope
     */
    private static function citiesSelect(
        string $relationship,
        string $label,
        string $actionName,
        string $actionLabel,
        Closure $scope,
    ): Select {
        return Select::make($relationship)
            ->label($label)
            ->relationship(
                $relationship,
                'name',
                $scope,
            )
            ->multiple()
            ->preload()
            ->searchable()
            ->native(false)
            ->hintAction(
                Action::make($actionName)
                    ->label($actionLabel)
                    ->visible(fn (string $operation): bool => $operation !== 'view')
                    ->action(function (Set $set) use ($relationship, $scope): void {
                        $ids = [];

                        foreach ($scope(City::query())->get() as $city) {
                            if (! $city instanceof City) {
                                continue;
                            }

                            $ids[] = $city->id;
                        }

                        $set($relationship, $ids);
                    }),
            );
    }
}
