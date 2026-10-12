<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\SkinEnum;
use App\Support\LangVariant;

it('ensurePlayer creates once', function (): void {
    $a = registration()->ensurePlayer(3001);
    $b = registration()->ensurePlayer(3001);

    expect($a->tg_id)->toBe($b->tg_id)
        ->and($a->progress_step)->toBe(ProgressStepEnum::SPLASH);
});

it('setNick validates and uniqueness', function (): void {
    $p1 = registration()->ensurePlayer(3002);

    $invalid = registration()->setNick($p1, 'ab');
    expect($invalid->ok)->toBeFalse()
        ->and($invalid->error)->toBeIn(LangVariant::all('telegram.registration.error.nick_invalid'));

    $forbidden = registration()->setNick($p1, 'hero@me');
    expect($forbidden->ok)->toBeFalse()
        ->and($forbidden->error)->toBeIn(LangVariant::all('telegram.registration.error.nick_forbidden'));

    $ok = registration()->setNick($p1, 'HeroOne');
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->progress_step)->toBe(ProgressStepEnum::SET_SKIN);

    $p2 = registration()->ensurePlayer(3052);
    $taken = registration()->setNick($p2, 'HeroOne');
    expect($taken->ok)->toBeFalse()
        ->and($taken->error)->toBeIn(LangVariant::all('telegram.registration.error.nick_taken'));
});

it('setSkin writes each enum and advances to city', function (int $tgId, string $key): void {
    $p = registration()->ensurePlayer($tgId);
    $p = registration()->setNick($p, 'Skin' . ucfirst($key))->character;

    $ok = registration()->setSkin($p, $key);
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->skin)->toBe(SkinEnum::from($key))
        ->and($ok->character->progress_step)->toBe(ProgressStepEnum::SET_CITY);
})->with([
    [3004, 'mobile'],
    [3005, 'heavy'],
    [3006, 'ward'],
]);

it('setSkin rejects invalid key', function (): void {
    $p = registration()->ensurePlayer(3060);
    $p = registration()->setNick($p, 'BadSkinKey')->character;

    $bad = registration()->setSkin($p, 'nope');
    expect($bad->ok)->toBeFalse()
        ->and($bad->error)->toBeIn(LangVariant::all('telegram.registration.error.pick_skin_button'))
        ->and($p->fresh()->skin)->toBeNull()
        ->and($p->fresh()->progress_step)->toBe(ProgressStepEnum::SET_SKIN);
});

it('setSkin rejects wrong progress step', function (): void {
    $p = registration()->ensurePlayer(3061);
    $p = registration()->rise($p);

    $onNick = registration()->setSkin($p, 'mobile');
    expect($onNick->ok)->toBeFalse()
        ->and($onNick->error)->toBeIn(LangVariant::all('telegram.registration.error.pick_skin_button'));

    $p = registration()->setNick($p, 'SkinWrongStep')->character;
    $p = registration()->setSkin($p, 'mobile')->character;

    $onCity = registration()->setSkin($p, 'heavy');
    expect($onCity->ok)->toBeFalse()
        ->and($onCity->error)->toBeIn(LangVariant::all('telegram.registration.error.pick_skin_button'))
        ->and($p->fresh()->skin)->toBe(SkinEnum::Mobile)
        ->and($p->fresh()->progress_step)->toBe(ProgressStepEnum::SET_CITY);
});

it('setLocation rejects missing skin', function (): void {
    $p = registration()->ensurePlayer(3062);
    $p = registration()->setNick($p, 'NoSkinCity')->character;
    $p->progress_step = ProgressStepEnum::SET_CITY;
    $p->skin = null;
    $p->save();

    $city = registration()->cities()[0];
    $res = registration()->setLocation($p, $city->key);

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBeIn(LangVariant::all('telegram.registration.error.pick_city_button'))
        ->and($p->fresh()->city_id)->toBeNull()
        ->and($p->fresh()->progress_step)->toBe(ProgressStepEnum::SET_CITY);
});

it('setLocation only from enabled list', function (): void {
    $p = registration()->ensurePlayer(3003);
    $p = registration()->setNick($p, 'CityGuy')->character;
    $p = registration()->setSkin($p, 'mobile')->character;

    expect(registration()->setLocation($p, 'Nowhere')->ok)->toBeFalse();

    $hidden = App\Models\City::factory()->create([
        'name' => 'Скрытый',
        'enabled' => false,
    ]);
    expect(registration()->setLocation($p, $hidden->key)->ok)->toBeFalse();

    $city = registration()->cities()[0];
    $res = registration()->setLocation($p, $city->key);

    expect($res->ok)->toBeTrue()
        ->and($res->character->city_id)->toBe($city->id)
        ->and($res->character->birth_city_id)->toBe($city->id)
        ->and($res->character->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($res->character->quest_phase)->toBe(App\Enums\Quest\QuestPhaseEnum::Overseer)
        ->and($res->character->unlock_portal)->toBeFalse();
});

it('rise and backToSplash move between splash and nick', function (): void {
    $p = registration()->ensurePlayer(3101);
    expect($p->progress_step)->toBe(ProgressStepEnum::SPLASH);

    $p = registration()->rise($p);
    expect($p->progress_step)->toBe(ProgressStepEnum::SET_NICK);

    $p = registration()->backToSplash($p);
    expect($p->progress_step)->toBe(ProgressStepEnum::SPLASH);
});

it('backToNick from skin keeps username and clears skin', function (): void {
    $p = registration()->ensurePlayer(3103);
    $p = registration()->setNick($p, 'BackSkin')->character;
    expect($p->progress_step)->toBe(ProgressStepEnum::SET_SKIN);

    $p = registration()->backToNick($p);
    expect($p->progress_step)->toBe(ProgressStepEnum::SET_NICK)
        ->and($p->username)->toBe('BackSkin')
        ->and($p->skin)->toBeNull();
});

it('skin preview cache remembers and forgets', function (): void {
    $p = registration()->ensurePlayer(3104);
    $p = registration()->setNick($p, 'PreviewCache')->character;

    expect(registration()->skinPreview($p))->toBe(SkinEnum::Mobile);

    registration()->rememberSkinPreview($p, SkinEnum::Ward);
    expect(registration()->skinPreview($p))->toBe(SkinEnum::Ward);

    registration()->forgetSkinPreview($p);
    expect(registration()->skinPreview($p))->toBe(SkinEnum::Mobile);
});

it('pending deletes accumulate and clear', function (): void {
    $p = registration()->ensurePlayer(3102);
    $p = registration()->rememberPendingDelete($p, 11);
    $p = registration()->rememberPendingDelete($p, 12);
    $p = registration()->rememberPendingDelete($p, 11);

    expect(registration()->pendingDeletes($p))->toBe([11, 12]);

    $p = registration()->clearPendingDeletes($p);
    expect(registration()->pendingDeletes($p))->toBe([]);
});
