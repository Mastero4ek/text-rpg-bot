<?php

declare(strict_types=1);

use App\Actions\Bruiser\BruiserBluffAction;
use App\Actions\Bruiser\BruiserCompleteAction;
use App\Actions\Bruiser\BruiserOpenDebtAction;
use App\Actions\Bruiser\BruiserStartFightAction;
use App\Actions\Bruiser\BruiserTakeKnuckleAction;
use App\Enums\Npc\NpcEnum;
use App\Models\City;
use App\Quests\BruiserQuest;
use App\Services\DailyContract\DailyContractService;
use App\Services\Quest\QuestNpcPlacement;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('places bruiser at arena when city flag is on and contract is active', function (): void {
    $player = cityBruiserPhase(99201, 'BruiserFlagOn');
    $placement = app(QuestNpcPlacement::class);

    expect($placement->cityHasBruiser($player))->toBeTrue()
        ->and($placement->isBruiserAtArena($player))->toBeTrue()
        ->and(app(BruiserQuest::class)->awaitsChallenge($player))->toBeTrue();
});

it('hides bruiser at arena when city flag is off despite active contract', function (): void {
    $player = cityBruiserPhase(99202, 'BruiserFlagOff', City::KEY_ELDWOOD);
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_bruiser = false;
    $city->save();
    $player->refresh();

    $placement = app(QuestNpcPlacement::class);

    expect($placement->cityHasBruiser($player))->toBeFalse()
        ->and($placement->isBruiserAtArena($player))->toBeFalse()
        ->and(app(BruiserQuest::class)->awaitsChallenge($player))->toBeFalse();
});

it('shows bruiser quest panel on lists when flag is on', function (): void {
    $player = cityBruiserPhase(99203, 'BruiserListsOn');

    cityFlowCallback($player->tg_id, 9, 'city:lists');

    assertCityEditMarkupHas('quest:bruiser:talk');
    assertCityEditMarkupHas('quest:bruiser:fight');
});

it('opens lists hub without bruiser buttons when flag is off', function (): void {
    $player = cityBruiserPhase(99204, 'BruiserListsOff', City::KEY_THORNBREAK);
    $city = City::query()->where('key', City::KEY_THORNBREAK)->firstOrFail();
    $city->has_bruiser = false;
    $city->save();

    cityFlowCallback($player->tg_id, 9, 'city:lists');

    assertCityEditHas(__('telegram.location.lists'));
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:bruiser:');
    });
});

it('rejects bruiser actions with no_bruiser when city flag is off', function (): void {
    $player = cityBruiserPhase(99205, 'BruiserActOff', City::KEY_ANKRAT);
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->has_bruiser = false;
    $city->save();
    $player = $player->fresh();

    $error = __('telegram.quest.bruiser.error.no_bruiser');

    $bluff = app(BruiserBluffAction::class)->handle($player);
    $fight = app(BruiserStartFightAction::class)->handle($player);
    $complete = app(BruiserCompleteAction::class)->handle($player);
    $debt = app(BruiserOpenDebtAction::class)->handle($player);

    expect($bluff->ok)->toBeFalse()->and($bluff->error)->toBe($error)
        ->and($fight->ok)->toBeFalse()->and($fight->error)->toBe($error)
        ->and($complete->ok)->toBeFalse()->and($complete->error)->toBe($error)
        ->and($debt->ok)->toBeFalse()->and($debt->error)->toBe($error);

    $player->refresh();
    expect($player->bruiser_contract_active)->toBeTrue()
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeFalse();
});

it('hides knuckle pending panel and rejects take when flag is off', function (): void {
    $player = cityBruiserPhase(99206, 'BruiserKnuckleOff', City::KEY_ELDWOOD);
    $player->bruiser_contract_active = false;
    $player->bruiser_knuckle_pending = true;
    $player->save();

    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_bruiser = false;
    $city->save();
    $player = $player->fresh();

    expect(app(BruiserQuest::class)->hasKnucklePending($player))->toBeFalse();

    cityFlowCallback($player->tg_id, 9, 'city:lists');

    assertCityEditHas(__('telegram.location.lists'));
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:bruiser:take_knuckle');
    });

    $take = app(BruiserTakeKnuckleAction::class)->handle($player);

    expect($take->ok)->toBeFalse()
        ->and($take->error)->toBe(__('telegram.quest.bruiser.error.no_bruiser'))
        ->and($player->fresh()->bruiser_knuckle_pending)->toBeTrue();
});

it('treats bruiser as daily issuer when city flag is on', function (): void {
    $city = City::query()->where('key', City::KEY_ANKRAT)->firstOrFail();
    $city->has_bruiser = true;
    $city->save();

    expect(DailyContractService::cityHasIssuer($city->fresh(), NpcEnum::Bruiser))->toBeTrue()
        ->and(DailyContractService::cityHasDeliverySpot($city->fresh(), NpcEnum::Bruiser))->toBeTrue();
});

it('rejects bruiser as daily issuer when city flag is off', function (): void {
    $city = City::query()->where('key', City::KEY_ELDWOOD)->firstOrFail();
    $city->has_bruiser = false;
    $city->has_port = true;
    $city->save();

    expect(DailyContractService::cityHasIssuer($city->fresh(), NpcEnum::Bruiser))->toBeFalse()
        ->and(DailyContractService::cityHasDeliverySpot($city->fresh(), NpcEnum::Bruiser))->toBeFalse();
});
