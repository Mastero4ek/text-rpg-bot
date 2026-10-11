<?php

declare(strict_types=1);

use App\Actions\Healer\HealerCollectDebtSilverAction;
use App\Actions\Healer\HealerMarkRatWinAction;
use App\Actions\Healer\HealerStartRatFightAction;
use App\Actions\Healer\HealerTakeRatTailAction;
use App\Enums\Fight\FightEndUiEnum;
use App\Enums\Fight\FightKindEnum;
use App\Enums\Quest\LocationEnum;
use App\Quests\HealerQuest;
use App\Services\Fight\FightEndService;
use App\Services\Quest\QuestUnlockService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
    ]);
});

it('pays debt with silver and reopens arena', function (): void {
    $player = cityBruiserPhase(98501, 'DebtPay');
    $player->healer_debt_open = true;
    $player->silver = 66;
    $player->save();

    $res = app(HealerCollectDebtSilverAction::class)->handle($player->fresh());

    expect($res->ok)->toBeTrue();

    $player->refresh();
    $unlocks = app(QuestUnlockService::class);

    expect($player->healer_debt_open)->toBeFalse()
        ->and($player->healer_rat_loot_pending)->toBeFalse()
        ->and($player->silver)->toBe(36)
        ->and($player->bruiser_contract_active)->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Lists))->toBeTrue()
        ->and($unlocks->canOpen($player, LocationEnum::Arena))->toBeTrue();
});

it('rejects debt pay when silver is below thirty', function (): void {
    $player = cityBruiserPhase(98502, 'DebtPoor');
    $player->healer_debt_open = true;
    $player->silver = 29;
    $player->save();

    $res = app(HealerCollectDebtSilverAction::class)->handle($player->fresh());

    expect($res->ok)->toBeFalse()
        ->and($res->error)->toBe(__('telegram.quest.healer.error.debt_no_silver'))
        ->and($player->fresh()->healer_debt_open)->toBeTrue();
});

it('shows rat loot on win and closes debt after take_tail', function (): void {
    $player = cityBruiserPhase(98503, 'DebtRat');
    $player->healer_debt_open = true;
    $player->save();
    $expBefore = $player->exp;

    expect(app(HealerStartRatFightAction::class)->handle($player->fresh())->ok)->toBeTrue();

    $fight = fights()->findByTgId($player->tg_id);
    expect($fight->kind)->toBe(FightKindEnum::QUEST_HEALER);

    $result = app(FightEndService::class)->finishWin($player->fresh(), $fight);

    expect($result->replyUi)->toBe(FightEndUiEnum::HealerRatLoot);

    $player->refresh();

    expect($player->healer_debt_open)->toBeTrue()
        ->and($player->healer_rat_loot_pending)->toBeTrue()
        ->and($player->exp)->toBe($expBefore)
        ->and(bag()->ownsQuestItem($player->tg_id, 'rat_tail'))->toBeFalse();

    $take = app(HealerTakeRatTailAction::class)->handle($player->fresh());

    expect($take->ok)->toBeTrue();

    $player->refresh();

    expect($player->healer_debt_open)->toBeFalse()
        ->and($player->healer_rat_loot_pending)->toBeFalse()
        ->and(bag()->ownsQuestItem($player->tg_id, 'rat_tail'))->toBeFalse()
        ->and(app(QuestUnlockService::class)->canOpen($player, LocationEnum::Lists))->toBeTrue()
        ->and(app(QuestUnlockService::class)->canOpen($player, LocationEnum::Arena))->toBeTrue();
});
it('keeps debt open after rat lose', function (): void {
    $player = cityBruiserPhase(98504, 'DebtRatLose');
    $player->healer_debt_open = true;
    $player->save();

    expect(app(HealerStartRatFightAction::class)->handle($player->fresh())->ok)->toBeTrue();
    $fight = fights()->findByTgId($player->tg_id);

    $result = app(FightEndService::class)->finishLose($player->fresh(), $fight);

    expect($result->replyUi)->toBe(FightEndUiEnum::HealerDebt)
        ->and($player->fresh()->healer_debt_open)->toBeTrue()
        ->and($player->fresh()->healer_rat_loot_pending)->toBeFalse();
});

it('shows debt panel on city healer when debt is open', function (): void {
    $player = cityBruiserPhase(98505, 'DebtUi');
    $player->healer_debt_open = true;
    $player->save();

    expect(app(HealerQuest::class)->hasOpenDebt($player))->toBeTrue();

    cityFlowCallback($player->tg_id, 9, 'city:healer');

    assertCityEditMarkupHas('quest:healer:debt_pay');
    assertCityEditMarkupHas('quest:healer:debt_work');
});

it('rejects rat close when debt is not open', function (): void {
    $player = cityBruiserPhase(98506, 'DebtClosed');

    expect(app(HealerMarkRatWinAction::class)->handle($player)->ok)->toBeFalse()
        ->and(app(HealerTakeRatTailAction::class)->handle($player->fresh())->ok)->toBeFalse()
        ->and(app(HealerStartRatFightAction::class)->handle($player->fresh())->ok)->toBeFalse();
});

it('rejects take_tail without pending loot', function (): void {
    $player = cityBruiserPhase(98507, 'DebtNoLoot');
    $player->healer_debt_open = true;
    $player->healer_rat_loot_pending = false;
    $player->save();

    expect(app(HealerTakeRatTailAction::class)->handle($player->fresh())->ok)->toBeFalse();
});

it('rejects silver pay while rat loot is pending', function (): void {
    $player = cityBruiserPhase(98508, 'DebtPayPending');
    $player->healer_debt_open = true;
    $player->healer_rat_loot_pending = true;
    $player->silver = 66;
    $player->save();

    $res = app(HealerCollectDebtSilverAction::class)->handle($player->fresh());

    expect($res->ok)->toBeFalse()
        ->and($player->fresh()->healer_debt_open)->toBeTrue()
        ->and($player->fresh()->healer_rat_loot_pending)->toBeTrue()
        ->and($player->fresh()->silver)->toBe(66);
});
