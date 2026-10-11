<?php

declare(strict_types=1);

use App\Actions\Buyer\BuyerTurnInPurseAction;
use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Quests\BuyerQuest;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Quest\QuestUnlockService;
use App\Support\Telegram\TelegramHtml;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows turn-in panel in tavern when purse awaits', function (): void {
    $player = cityAwaitingBuyerTurnIn(98701, 'TurnMeet');
    $player->loadMissing('city');

    expect(app(BuyerQuest::class)->awaitsTurnIn($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:buyer');

    assertCityEditHas(TelegramHtml::escape($player->city->name));
    assertCityEditHas('buyer_tavern.png');
    assertCityEditMarkupHas('quest:buyer:turnin');
    assertCityEditMarkupHas(__('telegram.quest.buyer.btn.take_share'));
    expect(TelegramKeyboards::buyerTurnIn()['inline_keyboard'])->toHaveCount(1);
});

it('turns in purse with rewards level and full unlock', function (): void {
    $player = cityAwaitingBuyerTurnIn(98702, 'TurnOk');
    $goldBefore = $player->gold;

    $res = app(BuyerTurnInPurseAction::class)->handle($player);

    expect($res->ok)->toBeTrue();

    $player->refresh();

    expect($player->exp)->toBe(200)
        ->and($player->level)->toBe(1)
        ->and($player->gold)->toBe($goldBefore + 5)
        ->and($player->city_unlocked_full)->toBeTrue()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeFalse()
        ->and(app(BuyerQuest::class)->awaitsTurnIn($player))->toBeFalse()
        ->and(app(QuestNpcPlacement::class)->isBuyerAtTavern($player))->toBeTrue()
        ->and(app(QuestNpcPlacement::class)->isBruiserAtArena($player))->toBeFalse();

    $unlocks = app(QuestUnlockService::class);
    expect($unlocks->canOpen($player, LocationEnum::Portal))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Forest))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Board))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Arena))->toBeTrue()
        ->and($unlocks->canTravelByPortal($player))->toBeTrue();
});

it('allows turn-in while knuckle is pending', function (): void {
    $player = cityAwaitingBuyerTurnIn(98703, 'TurnPending');
    $player->bruiser_knuckle_pending = true;
    $player->save();

    $res = app(BuyerTurnInPurseAction::class)->handle($player->fresh());

    expect($res->ok)->toBeTrue();

    $player->refresh();

    expect($player->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and($player->bruiser_knuckle_pending)->toBeTrue()
        ->and($player->exp)->toBe(200)
        ->and($player->level)->toBe(1);
});

it('rejects turn-in without purse and second turn-in', function (): void {
    $player = cityBruiserPhase(98704, 'TurnNoPurse');
    $player->bruiser_contract_active = false;
    $player->exp = 170;
    $player->save();

    $missing = app(BuyerTurnInPurseAction::class)->handle($player->fresh());
    expect($missing->ok)->toBeFalse()
        ->and($missing->error)->toBe(__('telegram.quest.buyer.error.no_purse'));

    $ready = cityAwaitingBuyerTurnIn(98705, 'TurnTwice');
    expect(app(BuyerTurnInPurseAction::class)->handle($ready)->ok)->toBeTrue();

    $again = app(BuyerTurnInPurseAction::class)->handle($ready->fresh());
    expect($again->ok)->toBeFalse()
        ->and($again->error)->toBe(__('telegram.quest.buyer.error.turn_in_phase'));

    $ready->refresh();
    expect($ready->exp)->toBe(200)
        ->and($ready->quest_phase)->toBe(QuestPhaseEnum::Done);
});

it('rejects turn-in during wrong phase', function (): void {
    $player = cityBuyerPhase(98706, 'TurnPhase');

    $res = app(BuyerTurnInPurseAction::class)->handle($player);

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('telegram.quest.buyer.error.turn_in_phase'));
});

it('keeps shop on board and opens shop after done', function (): void {
    $player = cityAwaitingBuyerTurnIn(98707, 'TurnBoard');

    cityFlowCallback($player->tg_id, 9, 'city:board');
    assertCityEditHas(__('telegram.location.board_empty'));
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:buyer:turnin');
    });

    expect(app(BuyerTurnInPurseAction::class)->handle($player->fresh())->ok)->toBeTrue();

    cityFlowCallback($player->tg_id, 10, 'city:buyer');
    assertCityEditHas(__('telegram.npc.buyer.offer'));
    assertCityEditMarkupHas('city:buyer:chest');
});

it('turns in via telegram callback and opens city hub', function (): void {
    $player = cityAwaitingBuyerTurnIn(98708, 'TurnTg');

    cityFlowCallback($player->tg_id, 9, 'quest:buyer:turnin');

    $player->refresh();
    expect($player->exp)->toBe(200)
        ->and($player->level)->toBe(1)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeFalse();

    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas('city:tavern');
});
