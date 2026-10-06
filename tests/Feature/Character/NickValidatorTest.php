<?php

declare(strict_types=1);

use App\Support\NickValidator;

it('accepts valid latin and cyrillic nicks', function (): void {
    $validator = app(NickValidator::class);

    expect($validator->validate('Hero_One'))->toBeNull()
        ->and($validator->validate('Герой-1'))->toBeNull()
        ->and($validator->validate('Ab C'))->toBeNull();
});

it('rejects short long and illegal alphabet', function (): void {
    $validator = app(NickValidator::class);
    $limits = gameConfig()->onboarding()['nick'];

    expect($validator->validate('ab'))->toBe(__('errors.nick_invalid', [
        'nickMin' => $limits['min'],
        'nickMax' => $limits['max'],
    ]))
        ->and($validator->validate(str_repeat('a', $limits['max'] + 1)))->toBe(__('errors.nick_invalid', [
            'nickMin' => $limits['min'],
            'nickMax' => $limits['max'],
        ]))
        ->and($validator->validate('hero@me'))->toBe(__('errors.nick_invalid', [
            'nickMin' => $limits['min'],
            'nickMax' => $limits['max'],
        ]))
        ->and($validator->validate('hero!'))->toBe(__('errors.nick_invalid', [
            'nickMin' => $limits['min'],
            'nickMax' => $limits['max'],
        ]));
});

it('rejects forbidden stems with leet and yo folding', function (): void {
    $validator = app(NickValidator::class);

    expect($validator->validate('blyat'))->toBe(__('errors.nick_forbidden'))
        ->and($validator->validate('Хуйло'))->toBe(__('errors.nick_forbidden'))
        ->and($validator->validate('долбоеб'))->toBe(__('errors.nick_forbidden'))
        ->and($validator->validate('httpHero'))->toBe(__('errors.nick_forbidden'))
        ->and($validator->validate('bitcoiner'))->toBe(__('errors.nick_forbidden'))
        ->and($validator->validate('наркот'))->toBe(__('errors.nick_forbidden'));
});

it('setNick action uses validator and uniqueness', function (): void {
    $p1 = characters()->createDraft(1101);
    $fail = app(App\Actions\Character\CharacterSetNickAction::class)->handle($p1, 'хуй');

    expect($fail->ok)->toBeFalse()
        ->and($fail->error)->toBe(__('errors.nick_forbidden'));

    $ok = app(App\Actions\Character\CharacterSetNickAction::class)->handle($p1, 'NickOne');
    expect($ok->ok)->toBeTrue()
        ->and($ok->character->username)->toBe('NickOne');

    $p2 = characters()->createDraft(1102);
    $taken = app(App\Actions\Character\CharacterSetNickAction::class)->handle($p2, 'nickone');

    expect($taken->ok)->toBeFalse()
        ->and($taken->error)->toBe(__('errors.nick_taken'));
});
