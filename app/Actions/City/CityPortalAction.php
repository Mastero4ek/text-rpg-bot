<?php

declare(strict_types=1);

namespace App\Actions\City;

use App\Models\Character;
use App\Models\City;
use App\Support\ActionResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CityPortalAction
{
    public function handle(Character $character, int $targetCityId): ActionResult
    {
        return DB::transaction(function () use ($character, $targetCityId): ActionResult {
            if ($character->city_id === null) {
                return ActionResult::fail(__('errors.city_unavailable'));
            }

            if ($character->city_id === $targetCityId) {
                return ActionResult::fail(__('errors.same_city'));
            }

            $target = City::query()->whereKey($targetCityId)->where('enabled', true)->first();

            if (! $target instanceof City) {
                return ActionResult::fail(__('errors.city_unavailable'));
            }

            $cost = $target->portal_cost_silver;

            if ($cost > 0) {
                $paid = Character::query()
                    ->where('tg_id', $character->tg_id)
                    ->where('silver', '>=', $cost)
                    ->decrement('silver', $cost);

                if ($paid <= 0) {
                    return ActionResult::fail(__('errors.not_enough_silver'));
                }
            }

            $fresh = Character::query()->find($character->tg_id);

            if (! $fresh instanceof Character) {
                throw new RuntimeException('Character missing after portal.');
            }

            $fresh->city_id = $target->id;
            $fresh->save();

            return ActionResult::ok($fresh);
        });
    }
}
