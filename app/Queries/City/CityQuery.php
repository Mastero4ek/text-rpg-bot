<?php

declare(strict_types=1);

namespace App\Queries\City;

use App\Models\Backpack\BackpackCatalog;
use App\Models\Bag\BagCatalog;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CityQuery
{
    /**
     * @return Collection<int, City>
     */
    public function enabled(): Collection
    {
        return City::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function backpackInCityShop(int $cityId, string $catalogId): bool
    {
        return BackpackCatalog::query()
            ->whereKey($catalogId)
            ->where('enabled', true)
            ->whereHas('cities', function (Builder $query) use ($cityId): void {
                $query->whereKey($cityId);
            })
            ->exists();
    }

    public function bagInCityShop(int $cityId, string $catalogId): bool
    {
        return BagCatalog::query()
            ->whereKey($catalogId)
            ->where('enabled', true)
            ->whereHas('cities', function (Builder $query) use ($cityId): void {
                $query->whereKey($cityId);
            })
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function bagShopCatalogIds(int $cityId): array
    {
        $ids = [];

        foreach (
            BagCatalog::query()
                ->where('enabled', true)
                ->whereHas('cities', function (Builder $query) use ($cityId): void {
                    $query->whereKey($cityId);
                })
                ->orderBy('sort_order')
                ->orderBy('catalog_id')
                ->get() as $catalog
        ) {
            $ids[] = $catalog->catalog_id;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function backpackShopCatalogIds(int $cityId): array
    {
        $ids = [];

        foreach (
            BackpackCatalog::query()
                ->where('enabled', true)
                ->whereHas('cities', function (Builder $query) use ($cityId): void {
                    $query->whereKey($cityId);
                })
                ->orderBy('sort_order')
                ->orderBy('catalog_id')
                ->get() as $catalog
        ) {
            $ids[] = $catalog->catalog_id;
        }

        return $ids;
    }

    /**
     * @return Collection<int, EnemyCatalog>
     */
    public function forestCatalogs(int $cityId): Collection
    {
        return EnemyCatalog::query()
            ->where('enabled', true)
            ->whereHas('cities', function (Builder $query) use ($cityId): void {
                $query->whereKey($cityId);
            })
            ->orderBy('sort_order')
            ->orderBy('catalog_id')
            ->get();
    }

    /**
     * @return Collection<int, EnemyCatalog>
     */
    public function trainingCatalogs(int $cityId): Collection
    {
        return EnemyCatalog::query()
            ->where('enabled', true)
            ->whereHas('trainingCities', function (Builder $query) use ($cityId): void {
                $query->whereKey($cityId);
            })
            ->orderBy('sort_order')
            ->orderBy('catalog_id')
            ->get();
    }

    public function findEnabledByKey(string $key): ?City
    {
        return City::query()->where('key', $key)->where('enabled', true)->first();
    }

    /**
     * @return Collection<int, City>
     */
    public function portalTargets(int $currentCityId): Collection
    {
        return City::query()
            ->where('enabled', true)
            ->where('id', '!=', $currentCityId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
