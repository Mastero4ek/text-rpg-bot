<?php

declare(strict_types=1);

use App\Actions\Buyer\BuyerAcceptContractAction;
use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Quests\BuyerQuest;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Quest\QuestUnlockService;
use App\Support\Telegram\TelegramHtml;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows buyer panel on board during buyer phase', function (): void {
    $player = cityBuyerPhase(98301, 'BuyerMeet');
    $player->loadMissing('city');

    expect(app(BuyerQuest::class)->awaitsContract($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:board');

    assertCityEditHas(TelegramHtml::escape($player->city->name));
    assertCityEditMarkupHas('quest:buyer:accept');
    assertCityEditMarkupHas('city:home');
    assertCityEditMarkupHas(__('telegram.quest.buyer.btn.accept'));
    assertCityEditMarkupHas(__('telegram.quest.buyer.btn.refuse'));

    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'city:board:list:');
    });
});

it('returns to city hub when buyer meet is refused', function (): void {
    $player = cityBuyerPhase(98311, 'BuyerRefuse');

    cityFlowCallback($player->tg_id, 9, 'city:board');
    cityFlowCallback($player->tg_id, 10, 'city:home');

    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas('city:tavern');
    expect(app(BuyerQuest::class)->awaitsContract($player->fresh()))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'ancient_amulet'))->toBeTrue();
});

it('accepts contract with rewards unlocks arena and moves buyer to tavern', function (): void {
    $player = cityBuyerPhase(98302, 'BuyerTake');
    $silverBefore = $player->silver;

    $res = app(BuyerAcceptContractAction::class)->handle($player);

    expect($res->ok)->toBeTrue();

    $player->refresh();

    expect($player->exp)->toBe(130)
        ->and($player->silver)->toBe($silverBefore + 15)
        ->and($player->bruiser_contract_active)->toBeTrue()
        ->and($player->unlock_arena)->toBeTrue()
        ->and($player->buyer_at_tavern)->toBeTrue()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Bruiser)
        ->and(backpack()->owns($player->tg_id, 'ancient_amulet'))->toBeFalse()
        ->and(app(BuyerQuest::class)->awaitsContract($player))->toBeFalse()
        ->and(app(QuestNpcPlacement::class)->isBuyerAtTavern($player))->toBeTrue()
        ->and(app(QuestNpcPlacement::class)->isBuyerAtBoard($player))->toBeFalse();
});

it('opens lists with bruiser after contract', function (): void {
    $player = cityBuyerPhase(98303, 'BuyerArena');
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeTrue();
    $player->refresh();

    $unlocks = app(QuestUnlockService::class);
    expect($unlocks->canOpen($player, LocationEnum::Training))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Lists))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Arena))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:lists');
    assertCityEditHas(__('telegram.quest.bruiser.btn.talk'));
    assertCityEditMarkupHas('quest:bruiser:talk');
});

it('rejects contract without amulet and double accept', function (): void {
    $player = cityBuyerPhase(98304, 'BuyerNoAmulet');
    $amulet = backpack()->findOwned($player->tg_id, 'ancient_amulet');
    backpack()->removeOne($amulet);

    $missing = app(BuyerAcceptContractAction::class)->handle($player->fresh());
    expect($missing->ok)->toBeFalse()
        ->and($missing->error)->toBe(__('telegram.quest.buyer.error.no_amulet'));

    $player->refresh();
    expect($player->quest_phase)->toBe(QuestPhaseEnum::Buyer)
        ->and($player->buyer_at_tavern)->toBeFalse()
        ->and($player->unlock_arena)->toBeFalse()
        ->and($player->exp)->toBe(110);

    $ready = cityBuyerPhase(98305, 'BuyerTwice');
    expect(app(BuyerAcceptContractAction::class)->handle($ready)->ok)->toBeTrue();

    $again = app(BuyerAcceptContractAction::class)->handle($ready->fresh());
    expect($again->ok)->toBeFalse()
        ->and($again->error)->toBe(__('telegram.quest.buyer.error.already_done'))
        ->and(backpack()->owns($ready->tg_id, 'ancient_amulet'))->toBeFalse();

    $ready->refresh();
    expect($ready->exp)->toBe(130)
        ->and($ready->quest_phase)->toBe(QuestPhaseEnum::Bruiser);
});

it('blocks selling ancient amulet before contract', function (): void {
    $player = cityBuyerPhase(98306, 'BuyerSellLock');
    $amulet = backpack()->findOwned($player->tg_id, 'ancient_amulet');

    $sold = buyerService()->sellBackpackItem($player, $amulet->id);

    expect($sold->ok)->toBeFalse()
        ->and($sold->error)->toBe(__('errors.cannot_sell'))
        ->and(backpack()->owns($player->tg_id, 'ancient_amulet'))->toBeTrue();
});

it('keeps fog on board without unlock_board', function (): void {
    $player = cityBuyerPhase(98307, 'BuyerBoardFog');
    $player->unlock_board = false;
    $player->save();
    $player->loadMissing('city');
    $cityEscaped = TelegramHtml::escape($player->city->name);

    cityFlowCallback($player->tg_id, 9, 'city:board');

    assertCityEditHas($cityEscaped);
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:buyer:accept');
    });
});

it('keeps fog on lists before contract', function (): void {
    $player = cityBuyerPhase(98308, 'BuyerListsFog');
    $player->loadMissing('city');
    $cityEscaped = TelegramHtml::escape($player->city->name);

    $unlocks = app(QuestUnlockService::class);
    expect($unlocks->canOpen($player, LocationEnum::Training))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Lists))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Arena))->toBeFalse();

    cityFlowCallback($player->tg_id, 9, 'city:home');
    assertCityEditMarkupHas('🔒');
    assertCityEditMarkupHas('city:lists');

    cityFlowCallback($player->tg_id, 10, 'city:lists');
    assertCityEditHas($cityEscaped);
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:bruiser:');
    });
});

it('accepts contract via telegram callback and opens city hub', function (): void {
    $player = cityBuyerPhase(98309, 'BuyerTg');

    cityFlowCallback($player->tg_id, 9, 'quest:buyer:accept');

    $player->refresh();
    expect($player->exp)->toBe(130)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Bruiser)
        ->and($player->buyer_at_tavern)->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'ancient_amulet'))->toBeFalse();

    assertCityEditMarkupHas('city:gates');
    assertCityEditMarkupHas('city:tavern');
    assertCityEditMarkupHas('city:lists');
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:buyer:accept');
    });
});

it('shows empty board after contract instead of buyer panel', function (): void {
    $player = cityBuyerPhase(98310, 'BuyerBoardAfter');
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:board');

    assertCityEditHas(__('telegram.location.board_empty'));
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:buyer:accept');
    });
});

it('shows buyer in tavern after contract', function (): void {
    $player = cityBuyerPhase(98312, 'BuyerTavern');
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:tavern');

    assertCityEditMarkupHas('city:buyer');
    assertCityEditMarkupHas(__('telegram.btn.buyer'));
});
