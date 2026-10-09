<?php

declare(strict_types=1);

use App\Actions\City\CityPortalAction;
use App\Models\City;

it('moves only city_id when portal silver is enough', function (): void {
    $yasen = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $liman = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $p = placeInCity(characters()->createDraft(9101), City::KEY_ANKRAT);
    $birth = $p->birth_city_id;
    $p->silver = $liman->portal_cost_silver;
    $p->save();

    $res = app(CityPortalAction::class)->handle($p, $liman->id);

    expect($res->ok)->toBeTrue()
        ->and($res->character->city_id)->toBe($liman->id)
        ->and($res->character->birth_city_id)->toBe($birth)
        ->and($res->character->birth_city_id)->toBe($yasen->id)
        ->and($res->character->silver)->toBe(0);
});

it('rejects portal when silver is short', function (): void {
    $liman = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $p = placeInCity(characters()->createDraft(9102), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();

    $res = app(CityPortalAction::class)->handle($p, $liman->id);

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('errors.not_enough_silver'))
        ->and($p->fresh()->city_id)->toBe($p->city_id);
});

it('rejects portal into the same city', function (): void {
    $p = placeInCity(characters()->createDraft(9103), City::KEY_ANKRAT);

    $res = app(CityPortalAction::class)->handle($p, $p->city_id);

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('errors.same_city'));
});

it('allows free portal without silver', function (): void {
    $liman = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $liman->portal_cost_silver = 0;
    $liman->save();
    $p = placeInCity(characters()->createDraft(9104), City::KEY_ANKRAT);
    $p->silver = 0;
    $p->save();

    $res = app(CityPortalAction::class)->handle($p, $liman->id);

    expect($res->ok)->toBeTrue()
        ->and($res->character->city_id)->toBe($liman->id)
        ->and($res->character->silver)->toBe(0);
});
