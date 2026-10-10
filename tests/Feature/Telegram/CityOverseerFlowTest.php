<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\StatKeyEnum;
use App\Models\City;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('opens overseer offer with reset and stat callbacks when points remain', function (): void {
    $player = cityDone(9801, 'OverseerOffer');
    $player->stat_points = 2;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.offer', [
        'points' => 2,
        'str' => $player->strength,
        'agi' => $player->agility,
        'inst' => $player->instinct,
        'vit' => $player->vitality,
    ])));
    assertCityEditHas('overseer_tavern.png');
    assertCityEditMarkupHas('city:overseer:reset');
    assertCityEditMarkupHas('city:overseer:stat:' . StatKeyEnum::STRENGTH->value);
    assertCityEditMarkupHas('city:tavern');
});

it('shows no points copy without stat buttons when free points are zero', function (): void {
    $player = cityDone(9802, 'OverseerNoPts');
    $player->stat_points = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.no_points', [
        'str' => $player->strength,
        'agi' => $player->agility,
        'inst' => $player->instinct,
        'vit' => $player->vitality,
    ])));
    assertCityEditMarkupHas('city:overseer:reset');
    assertCityEditMarkupHas('city:tavern');

    Http::assertNotSent(function ($request): bool {
        $body = $request->body();

        return str_contains($body, 'city:overseer:stat:');
    });
});

it('spends strength through telegram and refreshes offer', function (): void {
    $player = cityDone(9803, 'OverseerSpend');
    $player->stat_points = 1;
    $player->strength = 3;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer:stat:' . StatKeyEnum::STRENGTH->value);

    $player->refresh();
    expect($player->stat_points)->toBe(0)
        ->and($player->strength)->toBe(4);

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.no_points', [
        'str' => 4,
        'agi' => $player->agility,
        'inst' => $player->instinct,
        'vit' => $player->vitality,
    ])));
});

it('shows skip offer when onboarding was skipped', function (): void {
    $player = citySkipped(9804, 'OverseerSkip');

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.skip')));
    assertCityEditHas('overseer_tavern.png');
    assertCityEditMarkupHas('ob:not_now');
    assertCityEditMarkupHas('ob:hall');
});

it('opens reset confirm with yes and no', function (): void {
    $player = cityDone(9805, 'OverseerResetAsk');
    $cost = characters()->statResetGoldCost();

    cityFlowCallback($player->tg_id, 9, 'city:overseer:reset');

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.reset_confirm', [
        'gold' => $cost,
    ])));
    assertCityEditMarkupHas('city:overseer:reset_yes');
    assertCityEditMarkupHas(__('telegram.btn.yes'));
    assertCityEditMarkupHas(__('telegram.btn.no'));
});

it('resets stats for gold and shows reset done', function (): void {
    $player = cityDone(9806, 'OverseerResetOk');
    $cost = characters()->statResetGoldCost();
    $player->gold = $cost;
    $player->strength = 9;
    $player->stat_points = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer:reset_yes');

    $player->refresh();
    $start = gameConfig()->character()['start'];

    expect($player->gold)->toBe(0)
        ->and($player->strength)->toBe($start['strength'])
        ->and($player->stat_points)->toBeGreaterThan(0);

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.reset_done', [
        'points' => $player->stat_points,
        'str' => $player->strength,
        'agi' => $player->agility,
        'inst' => $player->instinct,
        'vit' => $player->vitality,
    ])));
    assertCityEditMarkupHas('city:overseer:stat:' . StatKeyEnum::STRENGTH->value);
});

it('shows not enough gold error on reset confirm yes', function (): void {
    $player = cityDone(9807, 'OverseerResetPoor');
    $player->gold = 0;
    $player->strength = 9;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer:reset_yes');

    $player->refresh();
    expect($player->strength)->toBe(9);

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.not_enough_gold')));
    assertCityEditMarkupHas('city:overseer');
});

it('shows no overseer error with back to tavern when flag is off', function (): void {
    $player = cityDone(9808, 'OverseerGone', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->has_overseer = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.no_overseer')));
    assertCityEditMarkupHas('city:tavern');
});

it('returns to tavern when arrived player opens overseer without skip', function (): void {
    $player = cityArrived(9809, 'OverseerArrived');

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->onboarding_skipped)->toBeFalse();

    assertCityEditHas(mb_trim(__('telegram.location.tavern')));
    assertCityEditMarkupHas('city:overseer');
});

it('shows dry no points error when spending with empty pool', function (): void {
    $player = cityDone(9810, 'OverseerSpendEmpty');
    $player->stat_points = 0;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:overseer:stat:' . StatKeyEnum::AGILITY->value);

    $player->refresh();
    expect($player->agility)->toBe(gameConfig()->character()['start']['agility']);

    assertCityEditHas(mb_trim(__('errors.no_stat_points')));
});
