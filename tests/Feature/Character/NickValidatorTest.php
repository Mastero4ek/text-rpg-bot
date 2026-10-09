<?php

declare(strict_types=1);

use App\Support\LangVariant;
use App\Support\NickValidator;

it('accepts valid latin and cyrillic nicks', function (): void {
    $validator = app(NickValidator::class);

    expect($validator->validate('Hero_One'))->toBeNull()
        ->and($validator->validate('Герой-1'))->toBeNull()
        ->and($validator->validate('Ab C'))->toBeNull();
});

it('rejects short long and illegal alphabet', function (): void {
    $validator = app(NickValidator::class);
    $invalid = LangVariant::all('telegram.registration.error.nick_invalid');
    $forbidden = LangVariant::all('telegram.registration.error.nick_forbidden');

    expect($validator->validate('ab'))->toBeIn($invalid)
        ->and($validator->validate(str_repeat('a', NickValidator::MAX_LENGTH + 1)))->toBeIn($invalid)
        ->and($validator->validate('hero@me'))->toBeIn($forbidden)
        ->and($validator->validate('hero!'))->toBeIn($invalid);
});

it('rejects forbidden stems with leet and yo folding', function (): void {
    $validator = app(NickValidator::class);
    $forbidden = LangVariant::all('telegram.registration.error.nick_forbidden');

    expect($validator->validate('blyat'))->toBeIn($forbidden)
        ->and($validator->validate('Хуйло'))->toBeIn($forbidden)
        ->and($validator->validate('долбоеб'))->toBeIn($forbidden)
        ->and($validator->validate('httpHero'))->toBeIn($forbidden)
        ->and($validator->validate('bitcoiner'))->toBeIn($forbidden)
        ->and($validator->validate('наркот'))->toBeIn($forbidden);
});

it('setNick action uses validator and uniqueness', function (): void {
    $p1 = characters()->createDraft(1101);
    $fail = app(App\Actions\Registration\SetNickAction::class)->handle($p1, 'хуй');

    expect($fail->ok)->toBeFalse()
        ->and($fail->error)->toBeIn(LangVariant::all('telegram.registration.error.nick_forbidden'));

    $ok = app(App\Actions\Registration\SetNickAction::class)->handle($p1, 'NickOne');
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->username)->toBe('NickOne');

    $p2 = characters()->createDraft(1102);
    $taken = app(App\Actions\Registration\SetNickAction::class)->handle($p2, 'nickone');

    expect($taken->ok)->toBeFalse()
        ->and($taken->error)->toBeIn(LangVariant::all('telegram.registration.error.nick_taken'));
});
