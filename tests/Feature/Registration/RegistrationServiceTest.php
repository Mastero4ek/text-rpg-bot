<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
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
        ->and($invalid->error)->toBeIn(LangVariant::all('telegram.registration.errors.nick_invalid'));

    $forbidden = registration()->setNick($p1, 'hero@me');
    expect($forbidden->ok)->toBeFalse()
        ->and($forbidden->error)->toBeIn(LangVariant::all('telegram.registration.errors.nick_forbidden'));

    $ok = registration()->setNick($p1, 'HeroOne');
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->progress_step)->toBe(ProgressStepEnum::SET_CITY);

    $p2 = registration()->ensurePlayer(3052);
    $taken = registration()->setNick($p2, 'HeroOne');
    expect($taken->ok)->toBeFalse()
        ->and($taken->error)->toBeIn(LangVariant::all('telegram.registration.errors.nick_taken'));
});

it('setLocation only from enabled list', function (): void {
    $p = registration()->ensurePlayer(3003);
    $p = registration()->setNick($p, 'CityGuy')->character;

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
        ->and($res->character->progress_step)->toBe(ProgressStepEnum::DONE);
});

it('rise and backToSplash move between splash and nick', function (): void {
    $p = registration()->ensurePlayer(3101);
    expect($p->progress_step)->toBe(ProgressStepEnum::SPLASH);

    $p = registration()->rise($p);
    expect($p->progress_step)->toBe(ProgressStepEnum::SET_NICK);

    $p = registration()->backToSplash($p);
    expect($p->progress_step)->toBe(ProgressStepEnum::SPLASH);
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
