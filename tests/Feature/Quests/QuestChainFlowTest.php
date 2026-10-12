<?php

declare(strict_types=1);

use App\Actions\Blacksmith\BlacksmithCompleteAction;
use App\Actions\Blacksmith\BlacksmithStartBagFightAction;
use App\Actions\Bruiser\BruiserBluffAction;
use App\Actions\Bruiser\BruiserStartFightAction;
use App\Actions\Buyer\BuyerAcceptContractAction;
use App\Actions\Buyer\BuyerTurnInPurseAction;
use App\Actions\Healer\HealerCollectDebtSilverAction;
use App\Actions\Healer\HealerGivePotionAction;
use App\Actions\Overseer\OverseerAcceptChallengeAction;
use App\Actions\Overseer\OverseerAgreeTrainingAction;
use App\Actions\Overseer\OverseerSkipGatesAction;
use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Enums\StatKeyEnum;
use App\Models\City;
use App\Quests\BuyerQuest;
use App\Quests\HealerQuest;
use App\Quests\OverseerQuest;
use App\Services\Fight\FightEndService;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Quest\QuestUnlockService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

function runOverseerTrain(App\Models\Character\Character $player): App\Models\Character\Character
{
    expect(app(OverseerAcceptChallengeAction::class)->handle($player)->ok)->toBeTrue();
    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    foreach ([StatKeyEnum::STRENGTH, StatKeyEnum::AGILITY, StatKeyEnum::INSTINCT] as $stat) {
        expect(characters()->spendStatPoint($player->fresh(), $stat->value)->ok)->toBeTrue();
    }

    expect(app(OverseerQuest::class)->completeAfterStats($player->fresh())->ok)->toBeTrue();

    return $player->fresh();
}

function runHealerGrant(App\Models\Character\Character $player): App\Models\Character\Character
{
    expect(app(HealerGivePotionAction::class)->handle($player)->ok)->toBeTrue();

    return $player->fresh();
}

function runBlacksmithWin(App\Models\Character\Character $player): App\Models\Character\Character
{
    expect(app(BlacksmithStartBagFightAction::class)->handle($player)->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);
    app(FightEndService::class)->finishWin($player->fresh(), $fight);

    return $player->fresh();
}

function runBuyerContract(App\Models\Character\Character $player): App\Models\Character\Character
{
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeTrue();

    return $player->fresh();
}

function runBuyerTurnIn(App\Models\Character\Character $player): App\Models\Character\Character
{
    expect(app(BuyerTurnInPurseAction::class)->handle($player)->ok)->toBeTrue();

    return $player->fresh();
}

it('completes fight path to done with knuckle', function (): void {
    $player = cityArrived(98601, 'FlowFight');
    $player = runOverseerTrain($player);
    $player = runHealerGrant($player);
    $player = runBlacksmithWin($player);
    $player = runBuyerContract($player);

    expect(app(BruiserStartFightAction::class)->handle($player)->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);
    app(FightEndService::class)->finishWin($player->fresh(), $fight);
    $player->refresh();

    expect($player->exp)->toBe(170)
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeTrue();

    $player = runBuyerTurnIn($player);

    expect($player->exp)->toBe(200)
        ->and($player->level)->toBe(1)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Done)
        ->and($player->city_unlocked_full)->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeTrue()
        ->and(app(QuestNpcPlacement::class)->isBuyerAtTavern($player))->toBeTrue()
        ->and(app(QuestNpcPlacement::class)->isBruiserAtArena($player))->toBeFalse();
});

it('completes bluff path to done without knuckle', function (): void {
    $player = cityArrived(98602, 'FlowBluff');
    $player = runOverseerTrain($player);
    $player = runHealerGrant($player);
    $player = runBlacksmithWin($player);
    $player = runBuyerContract($player);

    expect(app(BruiserBluffAction::class)->handle($player)->ok)->toBeTrue();
    $player->refresh();

    expect(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeFalse();

    $player = runBuyerTurnIn($player);

    expect($player->exp)->toBe(200)
        ->and($player->level)->toBe(1)
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeFalse();
});

it('completes skip plus tavern train path to done', function (): void {
    $player = cityArrived(98603, 'FlowSkip');
    expect(app(OverseerSkipGatesAction::class)->handle($player)->ok)->toBeTrue();
    expect(app(OverseerAgreeTrainingAction::class)->handle($player->fresh())->ok)->toBeTrue();

    app(OverseerQuest::class)->startFight($player->fresh());
    app(OverseerQuest::class)->advanceAfterWin($player->fresh());

    foreach ([StatKeyEnum::STRENGTH, StatKeyEnum::AGILITY, StatKeyEnum::INSTINCT] as $stat) {
        characters()->spendStatPoint($player->fresh(), $stat->value);
    }

    expect(app(OverseerQuest::class)->completeAfterStats($player->fresh())->ok)->toBeTrue();
    $player = runHealerGrant($player->fresh());
    $player = runBlacksmithWin($player);
    $player = runBuyerContract($player);
    expect(app(BruiserBluffAction::class)->handle($player)->ok)->toBeTrue();
    $player = runBuyerTurnIn($player->fresh());

    expect($player->exp)->toBe(200)
        ->and($player->level)->toBe(1)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Done);
});

it('completes debt path then bruiser then turn-in', function (): void {
    $player = cityArrived(98604, 'FlowDebt');
    $player = runOverseerTrain($player);
    $player = runHealerGrant($player);
    $player = runBlacksmithWin($player);
    $player = runBuyerContract($player);

    $player->silver = 100;
    $player->healer_debt_open = true;
    $player->save();

    expect(app(QuestUnlockService::class)->canOpen($player->fresh(), LocationEnum::Lists))->toBeFalse();
    expect(app(HealerCollectDebtSilverAction::class)->handle($player->fresh())->ok)->toBeTrue();
    $player->refresh();

    expect($player->healer_debt_open)->toBeFalse()
        ->and($player->bruiser_contract_active)->toBeTrue()
        ->and(app(BruiserBluffAction::class)->handle($player)->ok)->toBeTrue();

    $player = runBuyerTurnIn($player->fresh());

    expect($player->exp)->toBe(200)
        ->and($player->level)->toBe(1)
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Done);
});

it('runs the chain in two seeded cities', function (): void {
    foreach ([City::KEY_ANKRAT => 98605, City::KEY_ELDWOOD => 98606] as $cityKey => $tgId) {
        $player = cityArrived($tgId, 'Flow' . $cityKey, $cityKey);
        $player = runOverseerTrain($player);
        $player = runHealerGrant($player);
        $player = runBlacksmithWin($player);
        $player = runBuyerContract($player);
        expect(app(BruiserBluffAction::class)->handle($player)->ok)->toBeTrue();
        $player = runBuyerTurnIn($player->fresh());

        expect($player->quest_phase)->toBe(QuestPhaseEnum::Done)
            ->and($player->exp)->toBe(200)
            ->and($player->level)->toBe(1)
            ->and(app(QuestUnlockService::class)->canTravelByPortal($player))->toBeTrue();
    }
});

it('rejects stage jumps on chain seams', function (): void {
    $player = cityArrived(98607, 'FlowJump');

    expect(app(HealerGivePotionAction::class)->handle($player)->ok)->toBeFalse()
        ->and(app(BlacksmithCompleteAction::class)->handle($player)->ok)->toBeFalse()
        ->and(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeFalse()
        ->and(app(BruiserBluffAction::class)->handle($player)->ok)->toBeFalse()
        ->and(app(BuyerTurnInPurseAction::class)->handle($player)->ok)->toBeFalse();

    $player = runOverseerTrain($player);
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeFalse();

    $player = runHealerGrant($player);
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeFalse();

    $player = runBlacksmithWin($player);
    backpack()->removeOne(backpack()->findOwned($player->tg_id, 'ancient_amulet'));
    expect(app(BuyerAcceptContractAction::class)->handle($player->fresh())->ok)->toBeFalse();

    backpack()->addItem($player->tg_id, 'ancient_amulet');
    $player = runBuyerContract($player->fresh());
    expect(app(BuyerTurnInPurseAction::class)->handle($player)->ok)->toBeFalse();
});

it('keeps fog on locked locations mid chain', function (): void {
    $player = cityArrived(98608, 'FlowFog');
    $unlocks = app(QuestUnlockService::class);

    expect($unlocks->canOpen($player, LocationEnum::Portal))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Forest))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Board))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Lists))->toBeFalse();

    $player = runOverseerTrain($player);
    expect($unlocks->canOpen($player, LocationEnum::Portal))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Forest))->toBeFalse();

    $player = runHealerGrant($player);
    expect($unlocks->canOpen($player, LocationEnum::Forest))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Board))->toBeFalse();
});

it('does not duplicate rewards on repeated completes', function (): void {
    $player = cityArrived(98609, 'FlowDup');
    $player = runOverseerTrain($player);
    expect(app(OverseerQuest::class)->completeAfterStats($player)->ok)->toBeFalse();
    expect($player->fresh()->exp)->toBe(30);

    $player = runHealerGrant($player);
    expect(app(HealerGivePotionAction::class)->handle($player)->ok)->toBeFalse();
    expect($player->fresh()->exp)->toBe(70);

    $player = runBlacksmithWin($player);
    expect(app(BlacksmithCompleteAction::class)->handle($player)->ok)->toBeFalse();
    expect($player->fresh()->exp)->toBe(110);

    $player = runBuyerContract($player);
    expect(app(BuyerAcceptContractAction::class)->handle($player)->ok)->toBeFalse();
    expect($player->fresh()->exp)->toBe(130);

    expect(app(BruiserBluffAction::class)->handle($player->fresh())->ok)->toBeTrue();
    expect(app(BruiserBluffAction::class)->handle($player->fresh())->ok)->toBeFalse();
    expect($player->fresh()->exp)->toBe(170);

    $player = runBuyerTurnIn($player->fresh());
    expect(app(BuyerTurnInPurseAction::class)->handle($player)->ok)->toBeFalse();
    expect($player->fresh()->exp)->toBe(200)
        ->and(app(BuyerQuest::class)->awaitsTurnIn($player->fresh()))->toBeFalse();
});

it('ignores legacy hall callbacks on happy path', function (): void {
    $player = cityArrived(98610, 'FlowLegacy');
    $player = runOverseerTrain($player);

    cityFlowCallback($player->tg_id, 9, 'ob:hall');
    cityFlowCallback($player->tg_id, 10, 'ob:pass');

    $player->refresh();
    expect($player->quest_phase)->toBe(QuestPhaseEnum::Healer)
        ->and($player->exp)->toBe(30)
        ->and(app(HealerQuest::class)->awaitsPotion($player))->toBeTrue();
});
