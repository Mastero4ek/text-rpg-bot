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

it('opens overseer offer with stats above back and reset row', function (): void {
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
    assertTelegramMarkupBody(function (string $body): bool {
        return str_contains($body, 'city:overseer:reset')
            && str_contains($body, 'city:tavern')
            && str_contains($body, '"style":"success"')
            && str_contains($body, '"style":"danger"')
            && ! str_contains($body, 'city:overseer:reset_yes');
    });
});

it('shows no points copy with back and reset row only', function (): void {
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
    assertTelegramMarkupBody(function (string $body): bool {
        return str_contains($body, 'city:overseer:reset')
            && str_contains($body, 'city:tavern')
            && str_contains($body, '"style":"success"')
            && str_contains($body, '"style":"danger"')
            && ! str_contains($body, 'city:overseer:stat:');
    });
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

it('shows train offer when overseer waits in tavern after skip', function (): void {
    $player = cityArrivedSkipped(9804, 'OverseerSkip');

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    assertCityEditHas('overseer_tavern.png');
    assertCityEditMarkupHas('quest:overseer:train');
});

it('redisplays meet when arrived player opens city overseer before accept', function (): void {
    $player = cityArrived(9809, 'OverseerArrived');

    cityFlowCallback($player->tg_id, 9, 'city:overseer');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->overseer_at_tavern)->toBeFalse();

    assertCityEditMarkupHas('quest:overseer:accept');
    assertCityEditMarkupHas('quest:overseer:skip');
});

describe('stats', function (): void {
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
        assertCityEditMarkupHas('city:overseer:reset');
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
});

describe('reset', function (): void {
    it('opens reset confirm with stats price and yes no without resetting', function (): void {
        $player = cityDone(9805, 'OverseerResetAsk');
        $cost = characters()->statResetGoldCost();
        $player->gold = $cost;
        $player->strength = 12;
        $player->agility = 3;
        $player->instinct = 3;
        $player->vitality = 3;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:overseer:reset');

        $player->refresh();
        expect($player->gold)->toBe($cost)
            ->and($player->strength)->toBe(12);
        assertCityEditHas(mb_trim(__('telegram.npc.overseer.reset_confirm', [
            'str' => 12,
            'agi' => 3,
            'inst' => 3,
            'vit' => 3,
            'gold' => $cost,
        ])));
        assertCityEditMarkupHas('city:overseer:reset_yes');
        assertCityEditMarkupHas('city:overseer');
        assertCityEditMarkupHas('"style":"danger"');
        assertCityEditMarkupHas('"style":"success"');
    });

    it('cancels reset confirm and returns to offer without resetting', function (): void {
        $player = cityDone(9811, 'OverseerResetNo');
        $cost = characters()->statResetGoldCost();
        $player->gold = $cost;
        $player->strength = 9;
        $player->stat_points = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:overseer:reset');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:overseer');

        $player->refresh();
        expect($player->gold)->toBe($cost)
            ->and($player->strength)->toBe(9);
        assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.no_points', [
            'str' => 9,
            'agi' => $player->agility,
            'inst' => $player->instinct,
            'vit' => $player->vitality,
        ])));
        assertCityEditMarkupHas('city:overseer:reset');
        assertCityEditMarkupHas('city:tavern');
    });

    it('resets stats for gold through confirm path and shows reset done', function (): void {
        $player = cityDone(9806, 'OverseerResetOk');
        $cost = characters()->statResetGoldCost();
        $player->gold = $cost;
        $player->strength = 9;
        $player->stat_points = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:overseer:reset');
        telegramHttpFake();
        cityFlowCallback($player->tg_id, 10, 'city:overseer:reset_yes');

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
        assertCityEditMarkupHas('city:overseer:reset');
    });

    it('shows not enough gold error on reset yes and returns to offer without resetting', function (): void {
        $player = cityDone(9807, 'OverseerResetPoor');
        $player->gold = 0;
        $player->strength = 9;
        $player->stat_points = 0;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:overseer:reset_yes');

        $player->refresh();
        expect($player->strength)->toBe(9)
            ->and($player->gold)->toBe(0);

        assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.not_enough_gold')));
        assertCityEditMarkupHas('city:overseer:reset');
        assertCityEditMarkupHas('city:tavern');
    });

    it('shows no overseer on reset confirm when flag is off', function (): void {
        $player = cityDone(9812, 'OverseerResetGone', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->has_overseer = false;
        $city->save();
        $player->strength = 9;
        $player->gold = characters()->statResetGoldCost();
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:overseer:reset');

        assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.no_overseer')));
        assertCityEditMarkupHas('city:tavern');
        expect($player->fresh()->strength)->toBe(9);
    });

    it('shows no overseer on reset yes when flag is off', function (): void {
        $player = cityDone(9813, 'OverseerYesGone', City::KEY_ANKRAT);
        $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
        $city->has_overseer = false;
        $city->save();
        $cost = characters()->statResetGoldCost();
        $player->strength = 9;
        $player->gold = $cost;
        $player->save();

        cityFlowCallback($player->tg_id, 9, 'city:overseer:reset_yes');

        assertCityEditHas(mb_trim(__('telegram.npc.overseer.error.no_overseer')));
        assertCityEditMarkupHas('city:tavern');
        $player->refresh();
        expect($player->gold)->toBe($cost)
            ->and($player->strength)->toBe(9);
    });
});
