<?php

declare(strict_types=1);

use App\Actions\City\CityHospitalHealAction;
use App\Actions\City\CityPortalAction;
use App\Models\City;

it('moves only city_id when portal silver is enough', function (): void {
    $yasen = City::query()->where('key', City::KEY_YASEN)->firstOrFail();
    $liman = City::query()->where('key', City::KEY_LIMAN)->firstOrFail();
    $p = placeInCity(characters()->createDraft(9101), City::KEY_YASEN);
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
    $liman = City::query()->where('key', City::KEY_LIMAN)->firstOrFail();
    $p = placeInCity(characters()->createDraft(9102), City::KEY_YASEN);
    $p->silver = 0;
    $p->save();

    $res = app(CityPortalAction::class)->handle($p, $liman->id);

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('errors.not_enough_silver'))
        ->and($p->fresh()->city_id)->toBe($p->city_id);
});

it('rejects portal into the same city', function (): void {
    $p = placeInCity(characters()->createDraft(9103), City::KEY_YASEN);

    $res = app(CityPortalAction::class)->handle($p, $p->city_id);

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('errors.same_city'));
});

it('allows free portal without silver', function (): void {
    $liman = City::query()->where('key', City::KEY_LIMAN)->firstOrFail();
    $liman->portal_cost_silver = 0;
    $liman->save();
    $p = placeInCity(characters()->createDraft(9104), City::KEY_YASEN);
    $p->silver = 0;
    $p->save();

    $res = app(CityPortalAction::class)->handle($p, $liman->id);

    expect($res->ok)->toBeTrue()
        ->and($res->character->city_id)->toBe($liman->id)
        ->and($res->character->silver)->toBe(0);
});

it('fills hp and stamina for gold at hospital', function (): void {
    $p = placeInCity(characters()->createDraft(9105), City::KEY_YASEN);
    $p->gold = 1;
    $p->current_hp = 1;
    $p->current_stamina = 0;
    $p->save();

    $res = app(CityHospitalHealAction::class)->handle($p);

    expect($res->ok)->toBeTrue()
        ->and($res->character->gold)->toBe(0)
        ->and($res->character->current_hp)->toBe(characters()->maxHp($res->character))
        ->and($res->character->current_stamina)->toBe(characters()->maxStamina($res->character));
});

it('rejects hospital without gold or when already full', function (): void {
    $p = placeInCity(characters()->createDraft(9106), City::KEY_YASEN);
    $p->gold = 0;
    $p->current_hp = 1;
    $p->save();

    expect(app(CityHospitalHealAction::class)->handle($p)->ok)->toBeFalse();

    $p->gold = 1;
    $p->current_hp = characters()->maxHp($p);
    $p->current_stamina = characters()->maxStamina($p);
    $p->save();

    expect(app(CityHospitalHealAction::class)->handle($p)->error)->toBe(__('errors.hospital_already_full'));
});
