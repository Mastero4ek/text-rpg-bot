<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Models\Character;
use App\Models\City;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function cityFlowWebhook(array $payload): void
{
    test()->postJson('/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ])->assertOk();
}

function cityFlowCallback(int $tgId, int $messageId, string $data): void
{
    cityFlowWebhook([
        'update_id' => $tgId * 10 + $messageId,
        'callback_query' => [
            'id' => 'cb-' . $tgId . '-' . $messageId . '-' . $data,
            'data' => $data,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'message' => [
                'message_id' => $messageId,
                'chat' => ['id' => $tgId, 'type' => 'private'],
                'text' => 'city',
            ],
        ],
    ]);
}

function cityFlowText(int $tgId, int $messageId, string $text): void
{
    cityFlowWebhook([
        'update_id' => $tgId * 10 + $messageId,
        'message' => [
            'message_id' => $messageId,
            'text' => $text,
            'from' => ['id' => $tgId, 'is_bot' => false, 'first_name' => 'A'],
            'chat' => ['id' => $tgId, 'type' => 'private'],
        ],
    ]);
}

function cityArrived(int $tgId, string $nick, string $cityKey = City::KEY_YASEN): Character
{
    $player = registration()->ensurePlayer($tgId);
    $player = registration()->setNick($player, $nick)->character;

    return registration()->setLocation($player, $cityKey)->character;
}

function cityDone(int $tgId, string $nick, string $cityKey = City::KEY_YASEN): Character
{
    $player = cityArrived($tgId, $nick, $cityKey);
    $player->progress_step = ProgressStepEnum::DONE;
    $player->save();

    return $player->fresh();
}

function assertCityEditHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        return str_contains($request->url(), '/editMessageText')
            && str_contains((string) $request['text'], $needle);
    });
}

function assertCitySendHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], $needle);
    });
}

function assertCityEditMarkupHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup) && str_contains($markup, $needle);
    });
}

function assertCitySendMarkupHas(string $needle): void
{
    Http::assertSent(function (Request $request) use ($needle): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup) && str_contains($markup, $needle);
    });
}

it('shows first home with pass and hall cta after arrival', function (): void {
    $player = cityArrived(9601, 'ArriveHero');

    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED);

    cityFlowCallback($player->tg_id, 9, 'city:home');

    assertCityEditHas(mb_trim(__('telegram.city.first_home')));
    assertCityEditMarkupHas('ob:pass');
    assertCityEditMarkupHas('ob:hall');
    assertCityEditMarkupHas('"style":"danger"');
    assertCityEditMarkupHas('"style":"success"');
    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas('city:tavern');
});

it('enters onboarding hall from first home cta', function (): void {
    $player = cityArrived(9602, 'HallBound');

    cityFlowCallback($player->tg_id, 9, 'ob:hall');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::INTRO);

    assertCityEditHas(mb_trim(__('telegram.city.hall_gone')));
    assertCitySendHas(mb_trim(__('onboarding.intro')));
});

it('skips onboarding via pass and shows ordinary home', function (): void {
    $player = cityArrived(9603, 'PassBound');

    cityFlowCallback($player->tg_id, 9, 'ob:pass');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::DONE);

    assertCityEditHas(mb_trim(__('telegram.city.you_are_in')));
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup)
            && str_contains($markup, 'city:tavern')
            && ! str_contains($markup, 'ob:hall')
            && ! str_contains($markup, 'ob:pass');
    });
});

it('keeps hub open on arrived and opens tavern', function (): void {
    $player = cityArrived(9604, 'TavernOpen');

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED);

    assertCityEditHas(mb_trim(__('telegram.city.tavern')));
    assertCityEditMarkupHas('city:board');
    assertCityEditMarkupHas('city:hospital');
});

it('resumes first home on start while arrived', function (): void {
    $player = cityArrived(9605, 'StartArrive');

    cityFlowText($player->tg_id, 11, '/start');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED);

    assertCitySendHas(mb_trim(__('telegram.city.first_home')));
    assertCitySendMarkupHas('ob:hall');
});

it('welcome back on start when done shows ordinary home', function (): void {
    $player = cityDone(9606, 'DoneHero');

    cityFlowText($player->tg_id, 12, '/start');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::DONE);

    assertCitySendHas('С возвращением');
    assertCitySendHas(mb_trim(__('telegram.city.you_are_in')));
    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/sendMessage')) {
            return false;
        }

        $text = (string) $request['text'];
        $markup = $request['reply_markup'] ?? '';

        return str_contains($text, mb_trim(__('telegram.city.you_are_in')))
            && is_string($markup)
            && ! str_contains($markup, 'ob:hall');
    });
});

it('opens gates and arena from ordinary home', function (): void {
    $player = cityDone(9607, 'HubHero');

    cityFlowCallback($player->tg_id, 9, 'city:gates');
    assertCityEditHas(mb_trim(__('telegram.city.gates')));
    assertCityEditMarkupHas('city:portal');
    assertCityEditMarkupHas('city:forest');

    cityFlowCallback($player->tg_id, 9, 'city:arena');
    assertCityEditHas(mb_trim(__('telegram.city.arena')));
    assertCityEditMarkupHas('city:training');
    assertCityEditMarkupHas('city:pvp');
});

it('shows board stub from tavern', function (): void {
    $player = cityDone(9608, 'BoardHero');

    cityFlowCallback($player->tg_id, 9, 'city:board');

    assertCityEditHas(mb_trim(__('telegram.city.board_empty')));
    assertCityEditMarkupHas('city:board');
});

it('shows pvp stub without creating a fight', function (): void {
    $player = cityDone(9609, 'PvpHero');

    cityFlowCallback($player->tg_id, 9, 'city:pvp');

    expect(fights()->exists($player->tg_id))->toBeFalse();
    assertCityEditHas(mb_trim(__('telegram.city.pvp_empty')));
});

it('blocks city callbacks during mid onboarding', function (): void {
    $player = cityArrived(9610, 'MidOb');
    $player->progress_step = ProgressStepEnum::INTRO;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:home');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::INTRO);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('onboarding.hint_intro')));
    });
});

it('rejects shop in city without shop flag', function (): void {
    $player = cityDone(9611, 'NoShop', City::KEY_KURGAN);

    cityFlowCallback($player->tg_id, 9, 'city:shop');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('errors.no_shop')));
    });
});

it('rejects forest in city without forest flag', function (): void {
    $player = cityDone(9612, 'NoForest', City::KEY_KURGAN);

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('errors.no_forest')));
    });
});

it('rejects forest when player has no hp', function (): void {
    $player = cityDone(9613, 'NoHp');
    $player->current_hp = 0;
    $player->last_hp_update = now();
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], mb_trim(__('errors.no_hp')));
    });
});

it('hides gates button when city has neither portal nor forest', function (): void {
    $city = City::factory()->create([
        'name' => 'Безворотный',
        'enabled' => true,
        'has_portal' => false,
        'has_forest' => false,
        'has_shop' => true,
        'has_hospital' => true,
        'has_arena' => true,
        'has_training' => true,
    ]);

    $player = characters()->createDraft(9614);
    $player->username = 'NoGates';
    $player->progress_step = ProgressStepEnum::DONE;
    $player->birth_city_id = $city->id;
    $player->city_id = $city->id;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:home');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/editMessageText')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup)
            && str_contains($markup, 'city:tavern')
            && ! str_contains($markup, 'city:gates');
    });
});

it('ignores pass and hall when already done', function (): void {
    $player = cityDone(9615, 'AlreadyDone');

    cityFlowCallback($player->tg_id, 9, 'ob:pass');
    cityFlowCallback($player->tg_id, 9, 'ob:hall');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::DONE)
        ->and(fights()->exists($player->tg_id))->toBeFalse();
});
