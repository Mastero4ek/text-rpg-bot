<?php

declare(strict_types=1);

namespace App\Actions\City;

use App\Models\City;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CitySetCharactersMaxRowsAction
{
    public function handle(City $city, int $maxRows): City
    {
        if ($maxRows < 1) {
            throw new InvalidArgumentException('City characters max rows must be >= 1.');
        }

        return DB::transaction(function () use ($city, $maxRows): City {
            $locked = City::query()
                ->whereKey($city->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new ModelNotFoundException('City not found.');
            }

            $locked->characters_max_rows = $maxRows;
            $locked->save();

            return $locked;
        });
    }
}
