<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\SkinEnum;
use App\Models\Character;
use App\Models\City;
use App\Support\LangVariant;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function registrationWebhook(array $payload): void
{
    test()->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();
}

function registrationText(int $tgId, int $messageId, string $text): void
{
    registrationWebhook([
        'update_id' => $tgId * 10 + $messageId,
        'message' => [
            'message_id' => $messageId,
            'text' => $text,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
        ],
    ]);
}

function registrationCallback(int $tgId, int $messageId, string $data): void
{
    registrationWebhook([
        'update_id' => $tgId * 10 + $messageId,
        'callback_query' => [
            'id' => 'cb-' . $tgId . '-' . $messageId,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => $messageId,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'anchor',
            ],
        ],
    ]);
}

function assertRegistrationEditHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        return str_contains($request->url(), '/editMessageCaption')
            && str_contains((string) $request['caption'], $needle);
    });
}

function assertRegistrationEditHasVariant(string $langKey): void
{
    $variants = LangVariant::all($langKey);

    Http::assertSent(function (Request $request) use ($variants): bool {
        if (! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $caption = (string) $request['caption'];

        foreach ($variants as $variant) {
            if (str_contains($caption, $variant)) {
                return true;
            }
        }

        return false;
    });
}

function assertDeletedMessage(int $messageId): void
{
    Http::assertSent(function (Request $request) use ($messageId): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === $messageId;
    });
}

it('runs splash rise nick city happy path into intro', function (): void {
    $tgId = 5201;
    $city = registration()->cities()[0];

    registrationWebhook([
        'update_id' => 52010,
        'message' => [
            'message_id' => 10,
            'text' => '/start',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
        ],
    ]);

    $player = Character::query()->findOrFail($tgId);
    expect($player->progress_step)->toBe(ProgressStepEnum::SPLASH)
        ->and($player->tg_message_id)->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request->body(), mb_trim(__('telegram.registration.splash')))
            && str_contains($request->body(), 'registration_rest.png');
    });

    registrationWebhook([
        'update_id' => 52011,
        'callback_query' => [
            'id' => 'cb-rise',
            'data' => 'ob:rise',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'caption' => 'splash',
            ],
        ],
    ]);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'Капюшон надвинут — ты снова на ногах.')
            && str_contains($request->body(), 'registration_up.png');
    });

    registrationWebhook([
        'update_id' => 52012,
        'message' => [
            'message_id' => 20,
            'text' => 'RegHero',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
        ],
    ]);

    $player->refresh();
    expect($player->username)->toBe('RegHero')
        ->and($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN)
        ->and($player->skin)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'mobile.png')
            && str_contains($request->body(), 'Плащ лёгкий, шаг уже сделан.');
    });

    registrationWebhook([
        'update_id' => 52013,
        'callback_query' => [
            'id' => 'cb-skin-pick',
            'data' => 'ob:skin:pick:mobile',
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'caption' => 'skin',
            ],
        ],
    ]);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_CITY)
        ->and($player->skin?->value)->toBe('mobile');

    registrationWebhook([
        'update_id' => 52014,
        'callback_query' => [
            'id' => 'cb-city',
            'data' => 'ob:city:' . $city->key,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'city',
            ],
        ],
    ]);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->quest_phase->value)->toBe('Overseer')
        ->and($player->city_id)->toBe($city->id)
        ->and($player->birth_city_id)->toBe($city->id)
        ->and($player->skin?->value)->toBe('mobile');

    Http::assertSent(function (Request $request) use ($city): bool {
        $body = $request->body();

        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($body, 'quest:overseer:accept')
            && str_contains($body, 'quest:overseer:skip')
            && str_contains($body, $city->name);
    });

    Http::assertNotSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendPhoto')) {
            return false;
        }

        return str_contains($request->body(), 'quest:overseer:accept');
    });
});

it('edits nick screen on invalid nick and deletes user message', function (): void {
    $tgId = 5202;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 21, 'ab');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK)
        ->and($player->username)->toBeNull();

    assertDeletedMessage(21);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.ask_nick')));
    assertRegistrationEditHasVariant('telegram.registration.error.nick_invalid');
});

it('rejects forbidden nick via webhook', function (): void {
    $tgId = 5210;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 22, 'hero@me');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK)
        ->and($player->username)->toBeNull();

    assertDeletedMessage(22);
    assertRegistrationEditHasVariant('telegram.registration.error.nick_forbidden');
});

it('rejects taken nick via webhook', function (): void {
    registration()->setNick(registration()->ensurePlayer(5211), 'TakenNick');

    $tgId = 5212;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 23, 'takennick');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK)
        ->and($player->username)->toBeNull();

    assertDeletedMessage(23);
    assertRegistrationEditHasVariant('telegram.registration.error.nick_taken');
});

it('keeps pending nick deletes across failed attempts', function (): void {
    $tgId = 5213;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 31, 'ab');
    registrationText($tgId, 32, '!!');

    $player->refresh();
    expect(registration()->pendingDeletes($player))->toBe([31, 32]);

    assertDeletedMessage(31);
    assertDeletedMessage(32);
});

it('nudges splash when text arrives before rise', function (): void {
    $tgId = 5203;
    $player = registration()->ensurePlayer($tgId);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 30, 'TooEarly');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SPLASH);

    assertDeletedMessage(30);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.splash')));
    assertRegistrationEditHasVariant('telegram.registration.error.splash_need_rise');
});

it('nudges skin screen when text arrives instead of button', function (): void {
    $tgId = 5204;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'SkinWait')->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 40, 'not-a-button');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN);

    assertDeletedMessage(40);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_skin', ['name' => 'SkinWait'])));
    assertRegistrationEditHasVariant('telegram.registration.error.pick_skin_button');
});

it('nudges city screen when text arrives instead of button', function (): void {
    $tgId = 5224;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'CityWait')->character;
    $player = registration()->setSkin($player, 'mobile')->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 40, City::KEY_ANKRAT);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_CITY);

    assertDeletedMessage(40);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_city', ['name' => 'CityWait'])));
    assertRegistrationEditHasVariant('telegram.registration.error.pick_city_button');
});

it('rejects disabled city callback', function (): void {
    $tgId = 5214;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'NoHidden')->character;
    $player = registration()->setSkin($player, 'mobile')->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    $hidden = City::factory()->create([
        'name' => 'Скрытый',
        'enabled' => false,
    ]);

    registrationCallback($tgId, 99, 'ob:city:' . $hidden->key);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_CITY)
        ->and($player->city_id)->toBeNull();

    assertRegistrationEditHasVariant('telegram.registration.error.pick_city_button');
});

it('rejects unknown city callback', function (): void {
    $tgId = 5215;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'NoGhost')->character;
    $player = registration()->setSkin($player, 'ward')->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, 'ob:city:nowhere_city');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_CITY)
        ->and($player->city_id)->toBeNull();

    assertRegistrationEditHasVariant('telegram.registration.error.pick_city_button');
});

it('nudges on wrong-step registration callbacks', function (ProgressStepEnum $step, string $data, string $errorKey, string $screen): void {
    $tgId = match ($step) {
        ProgressStepEnum::SPLASH => 5216,
        ProgressStepEnum::SET_NICK => 5217,
        ProgressStepEnum::SET_SKIN => 5225,
        ProgressStepEnum::SET_CITY => 5218,
        default => 5219,
    };

    $player = registration()->ensurePlayer($tgId);

    if (
        in_array($step, [ProgressStepEnum::SET_NICK, ProgressStepEnum::SET_SKIN, ProgressStepEnum::SET_CITY], true)
    ) {
        $player = registration()->rise($player);
    }

    if ($step === ProgressStepEnum::SET_SKIN || $step === ProgressStepEnum::SET_CITY) {
        $player = registration()->setNick($player, 'WrongCb' . $tgId)->character;
    }

    if ($step === ProgressStepEnum::SET_CITY) {
        $player = registration()->setSkin($player, 'mobile')->character;
    }

    registration()->rememberTelegramMessage($player, $tgId, 99);
    registrationCallback($tgId, 99, $data);

    $player->refresh();
    expect($player->progress_step)->toBe($step);

    $replace = [];

    if ($step === ProgressStepEnum::SET_SKIN || $step === ProgressStepEnum::SET_CITY) {
        $replace = ['name' => 'WrongCb' . $tgId];
    }

    assertRegistrationEditHas(mb_trim(__($screen, $replace)));
    assertRegistrationEditHasVariant($errorKey);
})->with([
    [ProgressStepEnum::SPLASH, 'ob:back', 'telegram.registration.error.splash_need_rise', 'telegram.registration.splash'],
    [ProgressStepEnum::SPLASH, 'ob:city:ankrat', 'telegram.registration.error.splash_need_rise', 'telegram.registration.splash'],
    [ProgressStepEnum::SPLASH, 'ob:intro_fight', 'telegram.registration.error.splash_need_rise', 'telegram.registration.splash'],
    [ProgressStepEnum::SET_NICK, 'ob:rise', 'telegram.registration.error.nick_need_name', 'telegram.registration.ask_nick'],
    [ProgressStepEnum::SET_NICK, 'ob:city:ankrat', 'telegram.registration.error.nick_need_name', 'telegram.registration.ask_nick'],
    [ProgressStepEnum::SET_SKIN, 'ob:rise', 'telegram.registration.error.pick_skin_button', 'telegram.registration.pick_skin'],
    [ProgressStepEnum::SET_SKIN, 'ob:city:ankrat', 'telegram.registration.error.pick_skin_button', 'telegram.registration.pick_skin'],
    [ProgressStepEnum::SET_CITY, 'ob:rise', 'telegram.registration.error.pick_city_button', 'telegram.registration.pick_city'],
    [ProgressStepEnum::SET_CITY, 'ob:back', 'telegram.registration.error.pick_city_button', 'telegram.registration.pick_city'],
]);

it('nudges mid-reg side-entry callbacks', function (string $data): void {
    $tgId = 5220;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, $data);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK);

    assertRegistrationEditHas(mb_trim(__('telegram.registration.ask_nick')));
    assertRegistrationEditHasVariant('telegram.registration.error.nick_need_name');
})->with([
    'menu:profile',
    'city:home',
    'city:buyer:sell',
    'fight:start:wolf_0',
]);

it('start mid-reg resumes city, deletes junk /start, does not reset nick', function (): void {
    $tgId = 5221;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'KeepNick')->character;
    $player = registration()->setSkin($player, 'heavy')->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 50, '/start');

    $player->refresh();
    expect($player->username)->toBe('KeepNick')
        ->and($player->skin?->value)->toBe('heavy')
        ->and($player->progress_step)->toBe(ProgressStepEnum::SET_CITY);

    assertDeletedMessage(50);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_city', ['name' => 'KeepNick'])));
});

it('back to nick from skin via callback', function (): void {
    $tgId = 5226;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'SkinBack')->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, 'ob:back');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK)
        ->and($player->username)->toBe('SkinBack')
        ->and($player->skin)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'Капюшон надвинут — ты снова на ногах.')
            && str_contains($request->body(), 'registration_up.png');
    });
});

it('back to splash from nick via callback', function (): void {
    $tgId = 5206;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->rise($player);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, 'ob:back');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SPLASH);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), 'Ты сидишь у обочины на выжженном тракте.')
            && str_contains($request->body(), 'registration_rest.png');
    });
});

it('switches skin preview media and quote without writing skin', function (int $tgId, string $skin, string $file, string $quote): void {
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'Prev' . ucfirst($skin))->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    registrationCallback($tgId, 99, 'ob:skin:' . $skin);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN)
        ->and($player->skin)->toBeNull();

    Http::assertSent(function (Request $request) use ($file, $quote): bool {
        return str_contains($request->url(), '/editMessageMedia')
            && str_contains($request->body(), $file)
            && str_contains($request->body(), $quote);
    });
})->with([
    [5230, 'heavy', 'heavy.png', 'Сталь на плечах.'],
    [5231, 'ward', 'ward.png', 'Цвет плаща не из этой пыли.'],
]);

it('confirms skin pick and opens city caption', function (int $tgId, string $skin): void {
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'Pick' . ucfirst($skin))->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, 'ob:skin:pick:' . $skin);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_CITY)
        ->and($player->skin?->value)->toBe($skin);

    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_city', [
        'name' => 'Pick' . ucfirst($skin),
    ])));
})->with([
    [5240, 'mobile'],
    [5241, 'heavy'],
    [5242, 'ward'],
]);

it('confirms pick payload even when preview differs', function (): void {
    $tgId = 5250;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'PayloadSkin')->character;
    registration()->rememberSkinPreview($player, SkinEnum::Mobile);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, 'ob:skin:pick:ward');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_CITY)
        ->and($player->skin?->value)->toBe('ward');
});

it('ignores re-tap of current skin preview without media edit', function (): void {
    $tgId = 5251;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'SamePreview')->character;
    registration()->rememberSkinPreview($player, SkinEnum::Mobile);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);

    registrationCallback($tgId, 99, 'ob:skin:mobile');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN)
        ->and($player->skin)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/answerCallbackQuery');
    });

    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption');
    });
});

it('nudges on invalid skin callbacks', function (int $tgId, string $data): void {
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'BadSkin' . $tgId)->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, $data);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN)
        ->and($player->skin)->toBeNull();

    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_skin', ['name' => 'BadSkin' . $tgId])));
    assertRegistrationEditHasVariant('telegram.registration.error.pick_skin_button');
})->with([
    [5252, 'ob:skin:nope'],
    [5255, 'ob:skin:pick:nope'],
    [5256, 'ob:skin:pick:'],
]);

it('start mid-reg resumes skin without resetting nick', function (): void {
    $tgId = 5253;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'KeepSkinNick')->character;
    registration()->rememberSkinPreview($player, SkinEnum::Heavy);
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 60, '/start');

    $player->refresh();
    expect($player->username)->toBe('KeepSkinNick')
        ->and($player->skin)->toBeNull()
        ->and($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN);

    assertDeletedMessage(60);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_skin', ['name' => 'KeepSkinNick'])));
    assertRegistrationEditHas('Сталь на плечах.');
});

it('nudges mid-reg side-entry on skin step', function (int $tgId, string $data): void {
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'SkinSide' . $tgId)->character;
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationCallback($tgId, 99, $data);

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_SKIN)
        ->and($player->skin)->toBeNull();

    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_skin', ['name' => 'SkinSide' . $tgId])));
    assertRegistrationEditHasVariant('telegram.registration.error.pick_skin_button');
})->with([
    [5254, 'menu:profile'],
    [5257, 'city:home'],
    [5258, 'fight:start:wolf_0'],
]);
