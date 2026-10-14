<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\City;
use App\Services\CityMenuService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows overseer meet after arrival before skip', function (): void {
    $player = cityArrived(9601, 'ArriveHero');

    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED);

    cityFlowCallback($player->tg_id, 9, 'city:home');

    assertCityEditMarkupHas('quest:overseer:accept');
    assertCityEditMarkupHas('quest:overseer:skip');
    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return (str_contains($request->url(), '/editMessageMedia')
                || str_contains($request->url(), '/editMessageCaption')
                || str_contains($request->url(), '/editMessageText'))
            && str_contains($body, 'quest:overseer:accept')
            && ! str_contains($body, 'ob:hall')
            && ! str_contains($body, 'ob:pass');
    });
});

it('shows city hub with services after overseer skip', function (): void {
    $player = cityArrivedSkipped(9602, 'HubHero');

    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->overseer_at_tavern)->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:home');

    assertCityEditHas('круговой площади');
    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas('city:tavern');
    assertCityEditMarkupHas('city:board');
    assertCityEditMarkupHas('city:lists');
});

it('ignores legacy hall cta after skip', function (): void {
    $player = cityArrivedSkipped(9603, 'HallBound');

    cityFlowCallback($player->tg_id, 9, 'ob:hall');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->overseer_at_tavern)->toBeTrue()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Overseer)
        ->and(fights()->exists($player->tg_id))->toBeFalse();
});

it('opens tavern with overseer after skip', function (): void {
    $player = cityArrivedSkipped(9604, 'TavernOpen');

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED);

    assertCityEditHas(mb_trim(__('telegram.location.tavern')));
    assertCityEditMarkupHas('city:overseer');
});

it('opens tavern with npcs and board stays on root when done', function (): void {
    $player = cityDone(9614, 'TavernOpen');

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    assertCityEditHas(mb_trim(__('telegram.location.tavern')));
    assertCityEditMarkupHas('city:healer');
    assertCityEditMarkupHas('city:blacksmith');
    assertCityEditMarkupHas('city:buyer');
    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), '/editMessageMedia')) {
            $body = $request->body();

            return str_contains($body, 'city:healer')
                && ! str_contains($body, 'city:board');
        }

        if (! str_contains($request->url(), '/editMessageText')
            && ! str_contains($request->url(), '/editMessageCaption')) {
            return false;
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup)
            && str_contains($markup, 'city:healer')
            && ! str_contains($markup, 'city:board');
    });
});

it('resumes overseer meet on start while arrived', function (): void {
    $player = cityArrived(9605, 'StartArrive');

    cityFlowText($player->tg_id, 11, '/start');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED);

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return (str_contains($request->url(), '/sendPhoto')
                || str_contains($request->url(), '/editMessageMedia')
                || str_contains($request->url(), '/editMessageCaption')
                || str_contains($request->url(), '/sendMessage')
                || str_contains($request->url(), '/editMessageText'))
            && str_contains($body, 'quest:overseer:accept')
            && str_contains($body, 'quest:overseer:skip');
    });
});

it('resumes city hub on start after overseer skip', function (): void {
    $player = cityArrivedSkipped(9621, 'StartSkip');

    cityFlowText($player->tg_id, 11, '/start');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->overseer_at_tavern)->toBeTrue();

    assertCitySendHas('круговой площади');
    assertCitySendMarkupHas('city:lists');
});

it('sends ordinary home on start when done', function (): void {
    $player = cityDone(9606, 'DoneHero');
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 9;
    $player->save();

    cityFlowText($player->tg_id, 12, '/start');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::DONE)
        ->and($player->tg_message_id)->not->toBe(9);

    assertCitySendHas('круговой площади');
    Http::assertNotSent(function (Request $request): bool {
        return str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText');
    });
});

it('expands literal backslash-n in city description for ordinary home', function (): void {
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->description = 'Первая строка.\\n\\nВторая строка.';
    $city->save();

    $player = cityDone(9620, 'NlHero');
    $text = app(CityMenuService::class)->homeText($player->fresh());

    expect($text)->toBe("Первая строка.\n\nВторая строка.");
});

it('opens gates and arena from ordinary home', function (): void {
    $player = cityDone(9607, 'HubHero');

    cityFlowCallback($player->tg_id, 9, 'city:gates');
    assertCityEditHas('чужой горизонт');
    assertCityEditHas('gates.png');
    assertCityEditMarkupHas('city:portal');
    assertCityEditMarkupHas('city:forest');

    cityFlowCallback($player->tg_id, 10, 'city:lists');
    assertCityEditHas(mb_trim(__('telegram.location.lists')));
    assertCityEditMarkupHas('city:training');
    assertCityEditMarkupHas('city:arena');

    cityFlowCallback($player->tg_id, 11, 'city:arena');
    assertCityEditHas(mb_trim(__('telegram.location.fights_empty')));
    assertCityEditHas('arena.png');
    assertCityEditMarkupHas('city:lists');
});

it('shows daily board offers from root when quest done', function (): void {
    $player = cityDone(9608, 'BoardHero');

    cityFlowCallback($player->tg_id, 9, 'city:board');

    assertCityEditHas(mb_trim(__('telegram.location.board')));
    assertCityEditMarkupHas('daily:offer:');
    assertCityEditMarkupHas('city:board:list:all:1');
    assertCityEditMarkupHas('city:board:list:orders:1');
    assertCityEditMarkupHas('city:board:list:asks:1');
    assertCityEditMarkupHas('city:board:list:all:0');
    assertCityEditMarkupHas('city:board:list:all:2');
    assertCityEditMarkupHas('city:board:listpage:1:');
    assertCityEditMarkupHas('city:home');
    assertCityEditMarkupHas('"style":"danger"');
    assertCityEditMarkupHas('"style":"primary"');
});

it('shows fights stub without creating a fight', function (): void {
    $player = cityDone(9609, 'PvpHero');

    cityFlowCallback($player->tg_id, 9, 'city:fights');

    expect(fights()->exists($player->tg_id))->toBeFalse();
    assertCityEditHas(mb_trim(__('telegram.location.fights_empty')));
    assertCityEditMarkupHas('city:lists');
    assertCityEditMarkupHas('"style":"danger"');
    Http::assertSent(function (Request $request): bool {
        $body = urldecode($request->body());

        return (str_contains($request->url(), '/editMessageMedia')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText'))
            && ! str_contains($body, 'city:fights:list:')
            && ! str_contains($body, 'city:fights:listpage:');
    });
});

it('blocks city callbacks during registration nick step', function (): void {
    $player = registration()->ensurePlayer(9610);
    $player->progress_step = ProgressStepEnum::SET_NICK;
    $player->tg_chat_id = $player->tg_id;
    $player->tg_message_id = 9;
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:home');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::SET_NICK);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/sendMessage')
            || str_contains($request->url(), '/editMessageCaption')
            || str_contains($request->url(), '/editMessageText')
            || str_contains($request->url(), '/editMessageMedia');
    });
});

it('rejects buyer in city without buyer flag', function (): void {
    $player = cityDone(9611, 'NoBuyer', City::KEY_ELDWOOD);
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_buyer = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:buyer');

    assertCityEditHas(mb_trim(__('telegram.npc.buyer.error.no_buyer')));
});

it('rejects forest in city without forest flag', function (): void {
    $player = cityDone(9612, 'NoForest', City::KEY_ELDWOOD);
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_forest = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    assertCityEditHas(mb_trim(__('telegram.location.error.no_forest')));
});

it('rejects forest when player has no hp', function (): void {
    $player = cityDone(9613, 'NoHp');
    $player->current_hp = 0;
    $player->last_hp_update = now();
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:forest');

    assertCityEditHas(mb_trim(__('telegram.location.error.no_hp')));
});

it('shows gates empty when city has neither portal nor forest', function (): void {
    $city = City::factory()->create([
        'name' => 'Безворотный',
        'enabled' => true,
        'has_portal' => false,
        'has_forest' => false,
        'has_swamp' => false,
        'has_ruins' => false,
        'has_port' => false,
    ]);

    $player = characters()->createDraft(9614);
    $player->username = 'NoGates';
    $player->progress_step = ProgressStepEnum::DONE;
    $player->birth_city_id = $city->id;
    $player->city_id = $city->id;
    unlockQuestCity($player);

    cityFlowCallback($player->tg_id, 9, 'city:gates');

    assertCityEditHas(mb_trim(__('telegram.location.gates_empty')));
    assertCityEditMarkupHas('city:home');
});

it('shows overseer train offer in tavern after skip', function (): void {
    $player = cityArrivedSkipped(9615, 'SkipOverseer');

    cityFlowCallback($player->tg_id, 9, 'city:tavern');
    assertCityEditMarkupHas('city:overseer');

    cityFlowCallback($player->tg_id, 10, 'city:overseer');
    assertCityEditMarkupHas('quest:overseer:train');
});

it('shows tavern empty when no npc flags and no skip', function (): void {
    $city = City::factory()->create([
        'name' => 'ПустаяТаверна',
        'enabled' => true,
        'has_healer' => false,
        'has_blacksmith' => false,
        'has_buyer' => false,
        'has_overseer' => false,
        'has_barkeeper' => false,
        'has_bruiser' => false,
    ]);

    $player = characters()->createDraft(9617);
    $player->username = 'EmptyTav';
    $player->progress_step = ProgressStepEnum::DONE;
    $player->birth_city_id = $city->id;
    $player->city_id = $city->id;
    unlockQuestCity($player);

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    assertCityEditHas(mb_trim(__('telegram.location.tavern_empty')));
});

it('shows arena empty when both arena flags off', function (): void {
    $city = City::factory()->create([
        'name' => 'ТихаяАрена',
        'enabled' => true,
        'has_training_room' => false,
        'has_fights_list' => false,
    ]);

    $player = characters()->createDraft(9618);
    $player->username = 'EmptyArena';
    $player->progress_step = ProgressStepEnum::DONE;
    $player->birth_city_id = $city->id;
    $player->city_id = $city->id;
    unlockQuestCity($player);

    cityFlowCallback($player->tg_id, 9, 'city:arena');

    assertCityEditHas(mb_trim(__('telegram.location.arena_empty')));
});

it('ignores legacy hall callbacks after done', function (): void {
    $player = cityDone(9619, 'AlreadyDone');

    cityFlowCallback($player->tg_id, 9, 'ob:pass');
    cityFlowCallback($player->tg_id, 9, 'ob:hall');

    $player->refresh();
    expect($player->progress_step)->toBe(ProgressStepEnum::DONE)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and(fights()->exists($player->tg_id))->toBeFalse();
});

it('opens blacksmith submenu', function (): void {
    $player = cityDone(9620, 'SmithHero');

    cityFlowCallback($player->tg_id, 9, 'city:blacksmith');

    assertCityEditHas(mb_trim(__('telegram.npc.blacksmith.offer')));
    assertCityEditHas('blacksmith_tavern.png');
    assertCityEditMarkupHas('city:blacksmith:gear');
    assertCityEditMarkupHas('city:blacksmith:repair');
});
