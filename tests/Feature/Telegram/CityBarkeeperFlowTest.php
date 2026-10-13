<?php

declare(strict_types=1);

use App\Actions\DailyContract\DailyContractAcceptAction;
use App\Enums\DailyContract\DailyContractStatusEnum;
use App\Enums\Npc\NpcEnum;
use App\Models\City;
use App\Services\DailyContract\DailyContractService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('puts barkeeper first in tavern when flag is on', function (): void {
    $player = cityDone(99101, 'BarkeeperFirst');

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    assertCityEditMarkupHas('city:barkeeper');
    assertCityEditMarkupHas(__('telegram.btn.barkeeper'));
    assertTelegramMarkupBody(function (string $body): bool {
        $barkeeper = mb_strpos($body, 'city:barkeeper');
        $healer = mb_strpos($body, 'city:healer');

        return $barkeeper !== false
            && $healer !== false
            && $barkeeper < $healer;
    });
});

it('opens barkeeper offer with game button and back to tavern', function (): void {
    $player = cityDone(99102, 'BarkeeperOffer');

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');

    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.offer')));
    assertCityEditMarkupHas('city:barkeeper:game');
    assertCityEditMarkupHas(__('telegram.btn.lucky_barrel'));
    assertCityEditMarkupHas('city:tavern');
    assertCityEditHas('barkeeper_tavern.png');
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'daily:barkeeper:list');
    });
});

it('shows no barkeeper error with back to tavern when flag is off', function (): void {
    $player = cityDone(99103, 'BarkeeperGone', City::KEY_ELDWOOD);
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_barkeeper = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');

    assertCityEditHas(mb_trim(__('telegram.npc.barkeeper.error.no_barkeeper')));
    assertCityEditMarkupHas('city:tavern');
});

it('hides barkeeper button in tavern when flag is off', function (): void {
    $player = cityDone(99104, 'BarkeeperHidden', City::KEY_THORNBREAK);
    $city = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $city->has_barkeeper = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    assertCityEditHas(mb_trim(__('telegram.location.tavern')));
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'city:barkeeper');
    });
});

it('marks barkeeper in tavern when delivery is turn-inable', function (): void {
    $player = dailyPlayer(99105, 'BarkeeperMark');
    $board = dailyCity($player);
    $offer = dailyDeliveryOffer($player, NpcEnum::Barkeeper);
    app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
    $target = City::query()->findOrFail($offer->target_city_id);
    $player = placeInCity($player->fresh(), $target->key);

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    assertCityEditMarkupHas(__('telegram.daily.npc_mark', [
        'name' => __('telegram.btn.barkeeper'),
    ]));
});

it('shows daily contracts beside game and completes barkeeper turn-in', function (): void {
    $player = dailyPlayer(99106, 'BarkeeperTurnIn');
    $board = dailyCity($player);
    $offer = dailyDeliveryOffer($player, NpcEnum::Barkeeper);
    app(DailyContractAcceptAction::class)->handle($player, $board, $offer->id);
    $target = City::query()->findOrFail($offer->target_city_id);
    $player = placeInCity($player->fresh(), $target->key);
    $silverBefore = $player->silver;

    cityFlowCallback($player->tg_id, 9, 'city:barkeeper');
    assertCityEditMarkupHas('daily:barkeeper:list');
    assertCityEditMarkupHas('city:barkeeper:game');

    cityFlowCallback($player->tg_id, 10, 'daily:barkeeper:list');
    assertCityEditMarkupHas('daily:barkeeper:turnin:' . $offer->id);

    cityFlowCallback($player->tg_id, 11, 'daily:barkeeper:turnin:' . $offer->id);

    expect($offer->fresh()->status)->toBe(DailyContractStatusEnum::Completed)
        ->and(bag()->ownsQuestItem($player->tg_id, 'daily_parcel'))->toBeFalse()
        ->and($player->fresh()->silver)->toBe($silverBefore + $offer->reward_silver);
    assertCityEditHas('🪙 +' . $offer->reward_silver);
});

it('treats barkeeper as city issuer only when flag is on', function (): void {
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();

    expect(DailyContractService::cityHasIssuer($city, NpcEnum::Barkeeper))->toBeTrue();

    $city->has_barkeeper = false;
    $city->save();

    expect(DailyContractService::cityHasIssuer($city->fresh(), NpcEnum::Barkeeper))->toBeFalse();
});
