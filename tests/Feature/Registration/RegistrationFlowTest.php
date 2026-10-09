<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
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
        ->and($player->progress_step)->toBe(ProgressStepEnum::SET_CITY);

    registrationWebhook([
        'update_id' => 52013,
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
        ->and($player->city_id)->toBe($city->id)
        ->and($player->birth_city_id)->toBe($city->id);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/deleteMessage')
            && (int) $request['message_id'] === 1;
    });

    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), '/sendPhoto')) {
            return str_contains($request->body(), 'Чужая улица, чужой воздух.')
                && str_contains($request->body(), 'training_attendant.png');
        }

        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Чужая улица, чужой воздух.');
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

it('nudges city screen when text arrives instead of button', function (): void {
    $tgId = 5204;
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, 'CityWait')->character;
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
        ProgressStepEnum::SET_CITY => 5218,
        default => 5219,
    };

    $player = registration()->ensurePlayer($tgId);

    if ($step === ProgressStepEnum::SET_NICK || $step === ProgressStepEnum::SET_CITY) {
        $player = registration()->rise($player);
    }

    if ($step === ProgressStepEnum::SET_CITY) {
        $player = registration()->setNick($player, 'WrongCb' . $tgId)->character;
    }

    registration()->rememberTelegramMessage($player, $tgId, 99);
    registrationCallback($tgId, 99, $data);

    $player->refresh();
    expect($player->progress_step)->toBe($step);

    assertRegistrationEditHas(mb_trim(__($screen, $step === ProgressStepEnum::SET_CITY ? ['name' => 'WrongCb' . $tgId] : [])));
    assertRegistrationEditHasVariant($errorKey);
})->with([
    [ProgressStepEnum::SPLASH, 'ob:back', 'telegram.registration.error.splash_need_rise', 'telegram.registration.splash'],
    [ProgressStepEnum::SPLASH, 'ob:city:ankrat', 'telegram.registration.error.splash_need_rise', 'telegram.registration.splash'],
    [ProgressStepEnum::SPLASH, 'ob:intro_fight', 'telegram.registration.error.splash_need_rise', 'telegram.registration.splash'],
    [ProgressStepEnum::SET_NICK, 'ob:rise', 'telegram.registration.error.nick_need_name', 'telegram.registration.ask_nick'],
    [ProgressStepEnum::SET_NICK, 'ob:city:ankrat', 'telegram.registration.error.nick_need_name', 'telegram.registration.ask_nick'],
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
    registration()->rememberTelegramMessage($player, $tgId, 99);

    registrationText($tgId, 50, '/start');

    $player->refresh();
    expect($player->username)->toBe('KeepNick')
        ->and($player->progress_step)->toBe(ProgressStepEnum::SET_CITY);

    assertDeletedMessage(50);
    assertRegistrationEditHas(mb_trim(__('telegram.registration.pick_city', ['name' => 'KeepNick'])));
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
