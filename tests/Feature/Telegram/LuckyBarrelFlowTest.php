<?php

declare(strict_types=1);

use App\Actions\City\CityPortalAction;
use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Actions\LuckyBarrel\LuckyBarrelStartAction;
use App\Enums\Npc\NpcEnum;
use App\Enums\ProgressStepEnum;
use App\Models\City;
use App\Models\LuckyBarrel;
use App\Support\Random\FakeRandomSource;
use App\Support\Random\RandomSourceContract;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function bindFlowRatAt(int $barrelIndex): void
{
    $roll = ($barrelIndex - 1) / 5.0;
    app()->instance(RandomSourceContract::class, new FakeRandomSource([$roll]));
}

function flowPlayer(int $tgId, string $nick, int $silver = 100): App\Models\Character\Character
{
    $player = cityDone($tgId, $nick);
    $player->silver = $silver;
    $player->current_hp = 40;
    $player->current_stamina = 30;
    $player->tg_chat_id = $tgId;
    $player->tg_message_id = 9;
    $player->save();

    return $player->fresh();
}

it('P1 opens stake screen from offer with balance and config stakes', function (): void {
    $player = flowPlayer(99301, 'FlowP1', 91);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');
    assertCityEditMarkupHas('city:barkeeper:game');

    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game');

    assertCityEditHas('В кармане:');
    assertCityEditHas('🪙 91');
    assertCityEditMarkupHas('city:barkeeper:game:stake:20');
    assertCityEditMarkupHas('city:barkeeper:game:stake:50');
    assertCityEditMarkupHas('city:barkeeper:game:stake:100');
    assertCityEditHas('lucky_barrels.png');
});

it('P2 stake start debits silver and shows play without cash', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99302, 'FlowP2', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');

    expect($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->value('rat_index'))->toBe(1);

    assertCityEditHas('Ставка: 🪙 20');
    assertCityEditHas('Куш: 🪙 0');
    assertCityEditMarkupHas('city:barkeeper:game:open:1');
    Http::assertNotSent(function (Request $request): bool {
        return str_contains((string) $request->body(), 'city:barkeeper:game:cash');
    });
});

it('P3 safe open shows cash button and current win', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99303, 'FlowP3', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:open:2');

    $session = LuckyBarrel::query()->findOrFail($player->tg_id);

    expect($session->opened_barrels)->toBe([2])
        ->and($session->current_step)->toBe(1);

    assertCityEditHas('Куш: 🪙 24');
    assertCityEditMarkupHas('city:barkeeper:game:cash');
    assertCityEditMarkupHas('city:barkeeper:game:back');
});

it('P4 cash-out awards silver and shows win screen', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99304, 'FlowP4', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:open:2');
    cityFlowCallback($player->tg_id, 11, 'city:barkeeper:game:cash');

    expect($player->fresh()->silver)->toBe(104)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();

    assertCityEditHas('🪙 +24');
    assertCityEditMarkupHas('city:barkeeper:game');
    assertCityEditMarkupHas(__('telegram.btn.lucky_barrel_again'));
});

it('P7 again after win returns to stake without hanging session', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99305, 'FlowP7', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:open:2');
    cityFlowCallback($player->tg_id, 11, 'city:barkeeper:game:cash');
    cityFlowCallback($player->tg_id, 12, 'city:barkeeper:game');

    expect(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
    assertCityEditHas('В кармане:');
    assertCityEditMarkupHas('city:barkeeper:game:stake:20');
});

it('P8 offer and game resume play without second debit', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99306, 'FlowP8', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    expect($player->fresh()->silver)->toBe(80);

    cityFlowCallback($player->tg_id, 10, 'city:barkeeper');
    assertCityEditHas('Ставка: 🪙 20');
    expect($player->fresh()->silver)->toBe(80);

    cityFlowCallback($player->tg_id, 11, 'city:barkeeper:game');
    assertCityEditHas('Ставка: 🪙 20');
    expect($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeTrue();
});

it('P9 keeps daily turn-in on offer beside game button', function (): void {
    $player = dailyPlayer(99307, 'FlowP9');
    $board = dailyCity($player);
    $offer = dailyDeliveryOffer($player, NpcEnum::Barkeeper);
    app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
    $target = City::query()->findOrFail($offer->target_city_id);
    $player = placeInCity($player->fresh(), $target->key);
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 9;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');

    assertCityEditMarkupHas('daily:barkeeper:list');
    assertCityEditMarkupHas('city:barkeeper:game');
});

it('N1 game callbacks without barkeeper flag show error and no session', function (): void {
    $player = flowPlayer(99308, 'FlowN1', 100);
    $city = City::query()->findOrFail($player->city_id);
    $city->has_barkeeper = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game');
    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.error.no_barkeeper')));
    assertCityEditMarkupHas('city:tavern');

    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:stake:20');
    expect($player->fresh()->silver)->toBe(100)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N2 stake with not enough silver keeps balance and shows error', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99309, 'FlowN2', 10);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');

    expect($player->fresh()->silver)->toBe(10)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.error.not_enough_silver')));
    assertCityEditMarkupHas('city:barkeeper:game:stake:20');
});

it('N3 stake outside config does not debit', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99310, 'FlowN3', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:25');

    expect($player->fresh()->silver)->toBe(100)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.error.bad_stake')));
});

it('N4 open rat shows lose screen without refund or HP loss', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99311, 'FlowN4', 100);
    $hp = $player->current_hp;
    $stamina = $player->current_stamina;

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:open:1');

    $fresh = $player->fresh();

    expect($fresh->silver)->toBe(80)
        ->and($fresh->current_hp)->toBe($hp)
        ->and($fresh->current_stamina)->toBe($stamina)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();

    assertCityEditHas('🪙 -20');
    assertCityEditMarkupHas(__('telegram.btn.lucky_barrel_again'));
});

it('N6 cash at step zero leaves session and silver unchanged', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99312, 'FlowN6', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:cash');

    expect($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeTrue();
});

it('N7 open without session returns to stake screen', function (): void {
    $player = flowPlayer(99313, 'FlowN7', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:open:2');

    expect($player->fresh()->silver)->toBe(100);
    assertCityEditHas('В кармане:');
    assertCityEditMarkupHas('city:barkeeper:game:stake:20');
});

it('N9 portal kills session so new city cannot resume', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99314, 'FlowN9', 200);
    app(LuckyBarrelStartAction::class)->handle($player, 20);

    $target = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $target->portal_cost_silver = 0;
    $target->has_barkeeper = true;
    $target->save();

    $res = app(CityPortalAction::class)->handle($player->fresh(), $target->id);
    expect($res->ok)->toBeTrue()
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();

    $player = $player->fresh();
    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');
    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.offer')));
    assertCityEditMarkupHas('city:barkeeper:game');
    expect(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();
});

it('N11 mid-reg city game callback is gated without session', function (): void {
    $player = registration()->ensurePlayer(99315);
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 9;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game');

    expect(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse()
        ->and($player->fresh()->progress_step)->toBe(ProgressStepEnum::SET_NICK);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText')
            || str_contains($request->url(), '/editMessageMedia');
    });
});

it('back from play forfeits stake and returns to offer', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99316, 'FlowBack', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper:game:stake:20');
    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:back');

    expect($player->fresh()->silver)->toBe(80)
        ->and(LuckyBarrel::query()->whereKey($player->tg_id)->exists())->toBeFalse();

    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.offer')));
    assertCityEditMarkupHas('city:barkeeper:game');
});

it('uses lucky_barrels image on play and barkeeper image on offer', function (): void {
    bindFlowRatAt(1);
    $player = flowPlayer(99317, 'FlowImg', 100);

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');
    assertCityEditHas('barkeeper_tavern.png');

    cityFlowCallback($player->tg_id, 10, 'city:barkeeper:game:stake:20');
    assertCityEditHas('lucky_barrels.png');
});
