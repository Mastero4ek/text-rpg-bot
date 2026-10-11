<?php

declare(strict_types=1);

use App\Actions\Bruiser\BruiserBluffAction;
use App\Actions\Bruiser\BruiserCompleteAction;
use App\Actions\Bruiser\BruiserStartFightAction;
use App\Actions\Bruiser\BruiserTakeKnuckleAction;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Quest\LocationEnum;
use App\Enums\Quest\QuestPhaseEnum;
use App\Quests\BruiserQuest;
use App\Services\Fight\FightEndService;
use App\Services\Quest\QuestNpcPlacement;
use App\Services\Quest\QuestUnlockService;
use App\Telegram\Keyboards\TelegramKeyboards;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('shows bruiser panel on lists during contract', function (): void {
    $player = cityBruiserPhase(98401, 'BruiserMeet');

    expect(app(BruiserQuest::class)->awaitsChallenge($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:lists');

    assertCityEditMarkupHas('quest:bruiser:talk');
    assertCityEditMarkupHas('quest:bruiser:fight');
    assertCityEditMarkupHas(__('telegram.quest.bruiser.btn.talk'));
});

it('bluffs for purse without knuckle and same exp', function (): void {
    $player = cityBruiserPhase(98402, 'BruiserBluff');
    $expBefore = $player->exp;

    $res = app(BruiserBluffAction::class)->handle($player);

    expect($res->ok)->toBeTrue();

    $player->refresh();

    expect($player->exp)->toBe($expBefore + 40)
        ->and($player->bruiser_contract_active)->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeFalse()
        ->and(app(QuestNpcPlacement::class)->isBruiserAtArena($player))->toBeFalse();
});

it('wins fight for purse knuckle and same exp', function (): void {
    $player = cityBruiserPhase(98403, 'BruiserWin');
    $expBefore = $player->exp;

    expect(app(BruiserStartFightAction::class)->handle($player)->ok)->toBeTrue();

    $fight = fights()->findByTgId($player->tg_id);
    expect($fight->kind)->toBe(FightKindEnum::QUEST_BRUISER);

    $result = app(FightEndService::class)->finishWin($player->fresh(), $fight);

    expect($result->replyUi)->toBe(FightEndUiEnum::BruiserComplete)
        ->and(TelegramKeyboards::fightEndMarkup($result->replyUi)['inline_keyboard'][0][0]['callback_data'] ?? null)
        ->toBe('city:home');

    $player->refresh();

    expect($player->exp)->toBe($expBefore + 40)
        ->and($player->bruiser_contract_active)->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeTrue();
});

it('opens healer debt on lose and locks arena', function (): void {
    $player = cityBruiserPhase(98404, 'BruiserLose');

    expect(app(BruiserStartFightAction::class)->handle($player)->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);

    $result = app(FightEndService::class)->finishLose($player->fresh(), $fight);

    expect($result->replyUi)->toBe(FightEndUiEnum::BruiserKnockout)
        ->and(TelegramKeyboards::fightEndMarkup($result->replyUi)['inline_keyboard'][0][0]['callback_data'] ?? null)
        ->toBe('quest:healer:wake')
        ->and($result->replyText)->toBe(__('telegram.quest.bruiser.fight_lose'))
        ->and($result->replyText)->not->toContain('Очнулся-таки');

    $player->refresh();
    $unlocks = app(QuestUnlockService::class);

    expect($player->healer_debt_open)->toBeTrue()
        ->and($player->bruiser_contract_active)->toBeTrue()
        ->and($player->quest_phase)->toBe(QuestPhaseEnum::Bruiser)
        ->and($unlocks->canOpen($player, LocationEnum::Lists))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Arena))->toBeFalse()
        ->and($unlocks->canOpen($player, LocationEnum::Training))->toBeTrue()
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeFalse();

    cityFlowCallback($player->tg_id, 9, 'quest:healer:wake');
    assertCityEditMarkupHas('quest:healer:debt_pay');
    assertCityEditMarkupHas('quest:healer:debt_work');
    assertCityEditHas('Очнулся-таки');
});

it('rejects bluff and fight without contract', function (): void {
    $player = cityBruiserPhase(98405, 'BruiserNoContract');
    $player->bruiser_contract_active = false;
    $player->save();

    expect(app(BruiserBluffAction::class)->handle($player->fresh())->ok)->toBeFalse()
        ->and(app(BruiserStartFightAction::class)->handle($player->fresh())->ok)->toBeFalse();
});

it('does not grant knuckle on bluff and rejects double complete', function (): void {
    $player = cityBruiserPhase(98406, 'BruiserNoKnuckle');

    expect(app(BruiserBluffAction::class)->handle($player)->ok)->toBeTrue();
    $player->refresh();

    expect(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeFalse()
        ->and(app(BruiserCompleteAction::class)->handle($player)->ok)->toBeFalse()
        ->and(app(BruiserBluffAction::class)->handle($player->fresh())->ok)->toBeFalse();
});

it('shows lists hub after purse without quest buttons', function (): void {
    $player = cityBruiserPhase(98407, 'BruiserStub');
    expect(app(BruiserBluffAction::class)->handle($player)->ok)->toBeTrue();
    $player->refresh();

    cityFlowCallback($player->tg_id, 9, 'city:lists');

    assertCityEditHas(__('telegram.location.lists'));
    assertCityEditMarkupHas('city:training');
    assertCityEditMarkupHas('city:arena');
    Http::assertNotSent(function ($request): bool {
        return str_contains((string) $request->body(), 'quest:bruiser:');
    });
});

it('keeps knuckle pending when backpack is full and grants later', function (): void {
    $player = cityBruiserPhase(98408, 'BruiserKnucklePend');
    $player->backpack_max_rows = 1;
    $player->save();
    backpack()->addItem($player->tg_id, 'worn_mail');
    $expBefore = $player->fresh()->exp;

    expect(app(BruiserStartFightAction::class)->handle($player->fresh())->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);

    $result = app(FightEndService::class)->finishWin($player->fresh(), $fight);

    expect($result->replyUi)->toBe(FightEndUiEnum::BruiserKnucklePending)
        ->and($result->replyText)->toContain(mb_trim(strip_tags(__('telegram.quest.bruiser.knuckle_pending'))))
        ->and(TelegramKeyboards::fightEndMarkup($result->replyUi)['inline_keyboard'][0][1]['callback_data'] ?? null)
        ->toBe('quest:bruiser:take_knuckle');

    $player->refresh();

    expect($player->bruiser_knuckle_pending)->toBeTrue()
        ->and($player->bruiser_contract_active)->toBeFalse()
        ->and($player->exp)->toBe($expBefore + 40)
        ->and(bag()->ownsQuestItem($player->tg_id, 'buyer_purse'))->toBeTrue()
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeFalse();

    cityFlowCallback($player->tg_id, 9, 'city:lists');
    assertCityEditMarkupHas('quest:bruiser:take_knuckle');

    expect(app(BruiserTakeKnuckleAction::class)->handle($player->fresh())->ok)->toBeFalse();

    $filler = backpack()->findOwned($player->tg_id, 'worn_mail');
    backpack()->removeOne($filler);

    expect(app(BruiserTakeKnuckleAction::class)->handle($player->fresh())->ok)->toBeTrue();

    $player->refresh();

    expect($player->bruiser_knuckle_pending)->toBeFalse()
        ->and(backpack()->owns($player->tg_id, 'bruiser_knuckle'))->toBeTrue();
});

it('includes player bluff line in success speech', function (): void {
    $player = cityBruiserPhase(98409, 'BruiserBluffSay');

    $speech = app(BruiserQuest::class)->bluffSpeech($player);

    expect($speech)->toContain(mb_trim(strip_tags(__('telegram.quest.bruiser.say.bluff'))))
        ->and($speech)->toContain('мешочек');
});

it('includes narrative bridge and player threat line', function (): void {
    $player = cityBruiserPhase(98411, 'BruiserThreatSay');

    $speech = app(BruiserQuest::class)->threatSpeech($player);

    expect($speech)->toContain(mb_trim(strip_tags(__('telegram.quest.bruiser.talk'))))
        ->and($speech)->toContain('клеймом труса')
        ->and($speech)->toContain('Скупщик заберет долг твоей кровью');
});
