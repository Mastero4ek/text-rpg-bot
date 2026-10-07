<?php

declare(strict_types=1);

use App\Models\Backpack\BackpackCatalog;
use App\Models\City;
use App\Models\Enemy\EnemyCatalog;
use App\Queries\City\CityQuery;

it('hides shop and forest items without pivot rows', function (): void {
    $yasen = City::query()->where('key', City::KEY_YASEN)->firstOrFail();
    $knife = BackpackCatalog::query()->findOrFail('knife_0');
    $knife->cities()->sync([]);
    $wanderer = EnemyCatalog::query()->findOrFail('chance_wanderer');
    $wanderer->cities()->sync([]);

    $p = placeInCity(characters()->createDraft(9110), City::KEY_YASEN);
    $p->silver = 999;
    $p->save();

    expect(shopService()->buyWeapon($p->tg_id, 'knife_0')->ok)->toBeFalse()
        ->and(app(CityQuery::class)->forestCatalogs($yasen->id)->pluck('catalog_id')->all())
        ->not->toContain('chance_wanderer');
});

it('shows catalog only in attached cities', function (): void {
    $yasen = City::query()->where('key', City::KEY_YASEN)->firstOrFail();
    $liman = City::query()->where('key', City::KEY_LIMAN)->firstOrFail();
    $kurgan = City::query()->where('key', City::KEY_KURGAN)->firstOrFail();
    $knife = BackpackCatalog::query()->findOrFail('knife_0');
    $knife->cities()->sync([$yasen->id, $liman->id]);

    $query = app(CityQuery::class);

    expect($query->backpackInCityShop($yasen->id, 'knife_0'))->toBeTrue()
        ->and($query->backpackInCityShop($liman->id, 'knife_0'))->toBeTrue()
        ->and($query->backpackInCityShop($kurgan->id, 'knife_0'))->toBeFalse();
});

it('rejects shop buy when city has no blacksmith even if pivot exists', function (): void {
    $kurgan = City::query()->where('key', City::KEY_KURGAN)->firstOrFail();
    $kurgan->has_blacksmith = false;
    $kurgan->save();
    BackpackCatalog::query()->findOrFail('knife_0')->cities()->syncWithoutDetaching([$kurgan->id]);
    $p = placeInCity(characters()->createDraft(9111), City::KEY_KURGAN);
    $p->silver = 999;
    $p->save();

    expect(shopService()->buyWeapon($p->tg_id, 'knife_0')->ok)->toBeFalse()
        ->and(shopService()->buyWeapon($p->tg_id, 'knife_0')->error)->toBe(__('errors.no_blacksmith'));
});
