<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Models\City;
use App\Services\Quest\QuestUnlockService;
use App\Support\LangVariant;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('sets quest_phase Overseer after registration and fogs locked locations for Ankrat and Eldwood', function (string $cityKey, int $tgId): void {
    $city = City::query()->where('key', $cityKey)->firstOrFail();
    $player = cityArrivedSkipped($tgId, 'Fog' . $cityKey, $cityKey);

    expect($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Overseer)
        ->and($player->unlock_portal)->toBeFalse()
        ->and($player->unlock_forest)->toBeFalse()
        ->and($player->unlock_board)->toBeFalse()
        ->and($player->unlock_arena)->toBeFalse()
        ->and($player->city_unlocked_full)->toBeFalse()
        ->and($player->overseer_at_tavern)->toBeTrue();

    $unlocks = app(QuestUnlockService::class);

    expect($unlocks->canOpen($player, LocationEnum::Gates))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Tavern))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Training))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Lists))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Portal))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Forest))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Swamp))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Ruins))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Port))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Board))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Arena))->toBeFalse();

    $cityEscaped = TelegramHtml::escape($city->name);
    $fog = $unlocks->fogText($player);
    $fogTemplates = LangVariant::all('telegram.quest.fog');

    expect($fogTemplates)->toHaveCount(5)
        ->and($fog)->toContain($cityEscaped)
        ->and($fogTemplates)->toContain(str_replace($cityEscaped, ':city', $fog));

    cityFlowCallback($player->tg_id, 8, 'city:gates');
    assertCityEditMarkupHas('🔒');
    assertCityEditMarkupHas('city:portal');
    assertCityEditMarkupHas('city:forest');
    assertCityEditMarkupHas('city:swamp');
    assertCityEditMarkupHas('city:ruins');
    assertCityEditMarkupHas('city:port');

    cityFlowCallback($player->tg_id, 9, 'city:portal');
    assertCityEditHas($cityEscaped);
    assertCityEditMarkupHas('city:gates');

    cityFlowCallback($player->tg_id, 10, 'city:forest');
    assertCityEditHas($cityEscaped);

    cityFlowCallback($player->tg_id, 11, 'city:swamp');
    assertCityEditHas($cityEscaped);

    cityFlowCallback($player->tg_id, 12, 'city:board');
    assertCityEditHas($cityEscaped);

    cityFlowCallback($player->tg_id, 13, 'city:lists');
    assertCityEditHas($cityEscaped);

    cityFlowCallback($player->tg_id, 14, 'city:arena');
    assertCityEditHas($cityEscaped);
})->with([
    [City::KEY_ANKRAT, 9701],
    [City::KEY_ELDWOOD, 9702],
]);

it('keeps fog on portal and forest in mid phases without unlock flags', function (): void {
    $player = cityArrivedSkipped(9720, 'MidFog');
    $player->progress_step = ProgressStepEnum::DONE;
    $player->overseer_trained = true;
    $player->quest_phase = QuestPhaseEnum::Healer;
    $player->unlock_portal = false;
    $player->unlock_forest = false;
    $player->save();
    $player->loadMissing('city');

    $unlocks = app(QuestUnlockService::class);
    $cityEscaped = TelegramHtml::escape($player->city->name);

    expect($unlocks->canOpen($player, LocationEnum::Portal))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Forest))->toBeFalse();

    cityFlowCallback($player->tg_id, 9, 'city:portal');
    assertCityEditHas($cityEscaped);
    assertCityEditMarkupHas('city:gates');

    cityFlowCallback($player->tg_id, 10, 'city:forest');
    assertCityEditHas($cityEscaped);
    assertCityEditMarkupHas('city:gates');
});

it('does not mutate quest state on foreign quest callback or legacy ob:hall', function (): void {
    $player = cityArrived(9721, 'NoMutate');

    cityFlowCallback($player->tg_id, 9, 'quest:bruiser:bluff');
    cityFlowCallback($player->tg_id, 10, 'ob:hall');

    $player->refresh();

    expect($player->quest_phase)->toBe(QuestPhaseEnum::Overseer)
        ->and($player->progress_step)->toBe(ProgressStepEnum::ARRIVED)
        ->and($player->unlock_portal)->toBeFalse()
        ->and($player->overseer_trained)->toBeFalse()
        ->and($player->overseer_at_tavern)->toBeFalse();
});

it('gates missing character and banned player without NPE', function (): void {
    cityFlowCallback(9799, 1, 'city:portal');
    assertCitySendHas(mb_trim(__('common.press_start')));

    $player = cityArrived(9722, 'BannedFog');
    $player->banned_until = now()->addDay();
    $player->save();

    cityFlowCallback($player->tg_id, 9, 'city:portal');
    assertCitySendHas(mb_trim(__('errors.banned')));
});
