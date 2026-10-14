<?php

declare(strict_types=1);

use App\Enums\ProgressStepEnum;
use App\Services\Fight\FightEndService;
use App\Support\Demo\DemoMode;
use App\Support\Telegram\DailyContractCopy;

beforeEach(function (): void {
    config([
        'bot.demo.enabled' => true,
        'bot.demo.max_level' => 3,
    ]);
});

it('freezes exp silver gold and stat grants at demo max level', function (): void {
    $p = characters()->createDraft(9201);
    $p->level = 3;
    $p->exp = 1200;
    $p->silver = 50;
    $p->gold = 2;
    $p->stat_points = 1;
    $p->save();

    expect(DemoMode::isProgressionCapped($p))->toBeTrue();

    $afterExp = characters()->grantExp($p->fresh(), 100);
    $afterSilver = characters()->grantSilver($afterExp, 40);
    $afterGold = characters()->grantGold($afterSilver, 3);
    $afterStats = characters()->grantStatPoints($afterGold, 5);
    $afterBundle = characters()->addExpSilver($afterStats, 80, 25);

    expect($afterBundle->exp)->toBe(1200)
        ->and($afterBundle->silver)->toBe(50)
        ->and($afterBundle->gold)->toBe(2)
        ->and($afterBundle->stat_points)->toBe(1)
        ->and($afterBundle->level)->toBe(3);
});

it('still grants progression below demo max level', function (): void {
    $p = characters()->createDraft(9202);
    $p->level = 2;
    $p->exp = 1050;
    $p->silver = 10;
    $p->gold = 0;
    $p->stat_points = 0;
    $p->save();

    expect(DemoMode::isProgressionCapped($p))->toBeFalse();

    $after = characters()->addExpSilver($p->fresh(), 150, 20);

    expect($after->level)->toBe(3)
        ->and($after->exp)->toBe(1200)
        ->and($after->silver)->toBe(10 + 20 + 25)
        ->and($after->stat_points)->toBeGreaterThan(0);
});

it('does not freeze grants when demo mode is off', function (): void {
    config(['bot.demo.enabled' => false]);

    $p = characters()->createDraft(9203);
    $p->level = 3;
    $p->exp = 1200;
    $p->silver = 10;
    $p->gold = 1;
    $p->stat_points = 0;
    $p->save();

    $after = characters()->addExpSilver($p->fresh(), 200, 15);
    $after = characters()->grantGold($after, 2);
    $after = characters()->grantStatPoints($after, 4);

    expect($after->exp)->toBe(1400)
        ->and($after->silver)->toBe(10 + 15 + 11)
        ->and($after->gold)->toBe(3)
        ->and($after->stat_points)->toBe(4 + 1)
        ->and($after->level)->toBe(3);
});

it('uses demo max as effective level ceiling', function (): void {
    expect(characters()->effectiveLevelMax())->toBe(3);

    config(['bot.demo.enabled' => false]);

    expect(characters()->effectiveLevelMax())->toBe(15);
});

it('appends demo banner at the bottom of profile at any level', function (): void {
    $banner = __('profile.demo_banner', ['max' => 3]);

    $below = characters()->createDraft(9204);
    $below->level = 1;
    $below->username = 'DemoBelow';
    $below->save();

    $textBelow = characters()->profileText($below->fresh());

    expect($textBelow)->toContain('DemoBelow')
        ->and($textBelow)->toEndWith($banner);

    $capped = characters()->createDraft(9205);
    $capped->level = 3;
    $capped->username = 'DemoCap';
    $capped->save();

    $textCapped = characters()->profileText($capped->fresh());

    expect($textCapped)->toContain('DemoCap')
        ->and($textCapped)->toEndWith($banner);
});

it('omits demo banner when demo mode is off', function (): void {
    config(['bot.demo.enabled' => false]);

    $p = characters()->createDraft(9206);
    $p->username = 'FullGame';
    $p->save();

    $text = characters()->profileText($p->fresh());

    expect($text)->not->toContain(__('profile.demo_banner', ['max' => 3]))
        ->and($text)->toContain('FullGame');
});

it('shows demo frozen reward on fight win caption at max level', function (): void {
    $p = characters()->createDraft(9207);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'DemoWin';
    $p->level = 3;
    $p->exp = 1200;
    $p->silver = 40;
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 0;
    $fight->enemy = $enemy;
    $fight->player_hp = 10;
    $fight->save();

    $result = app(FightEndService::class)->finishWin($p->fresh(), $fight);

    expect($result->editText)->toContain(__('profile.demo_reward_frozen'))
        ->and($result->editText)->toContain('⭐️ +0')
        ->and($result->editText)->toContain('🪙 +0')
        ->and($result->character->exp)->toBe(1200)
        ->and($result->character->silver)->toBe(40);
});

it('keeps normal fight win rewards below demo max level', function (): void {
    $p = characters()->createDraft(9208);
    $p->progress_step = ProgressStepEnum::DONE;
    $p->username = 'DemoWinOk';
    $p->level = 1;
    $p->exp = 200;
    $p->silver = 10;
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 0;
    $fight->enemy = $enemy;
    $fight->player_hp = 10;
    $fight->save();

    $beforeExp = $p->exp;
    $result = app(FightEndService::class)->finishWin($p->fresh(), $fight);

    expect($result->editText)->not->toContain(__('profile.demo_reward_frozen'))
        ->and($result->character->exp)->toBeGreaterThanOrEqual($beforeExp);
});

it('zeros daily card rewards and appends frozen notice at max level', function (): void {
    $p = dailyPlayer(9209, 'DemoDaily', 3);
    $contract = dailyOffer($p);
    $contract->reward_silver = 50;
    $contract->reward_exp = 20;
    $contract->save();

    $text = app(DailyContractCopy::class)->cardText($contract->fresh());

    expect($text)->toContain('🪙 +0')
        ->and($text)->toContain('⭐️ +0')
        ->and($text)->toContain(__('profile.demo_reward_frozen'));
});

it('keeps daily card reward numbers below demo max level', function (): void {
    $p = dailyPlayer(9216, 'DemoDailyOk', 2);
    $contract = dailyOffer($p);
    $contract->reward_silver = 50;
    $contract->reward_exp = 20;
    $contract->save();

    $text = app(DailyContractCopy::class)->cardText($contract->fresh());

    expect($text)->toContain('🪙 +50')
        ->and($text)->toContain('⭐️ +20')
        ->and($text)->not->toContain(__('profile.demo_reward_frozen'));
});

it('still allows spending silver and gold at demo max level', function (): void {
    $p = characters()->createDraft(9217);
    $p->level = 3;
    $p->silver = 100;
    $p->gold = 5;
    $p->save();

    $afterSilver = characters()->subtractExpSilver($p->fresh(), 0, 30);
    $afterGold = characters()->subtractGold($afterSilver, 2);

    expect($afterGold->silver)->toBe(70)
        ->and($afterGold->gold)->toBe(3)
        ->and($afterGold->exp)->toBe($p->exp);
});

it('returns null next exp threshold at demo max level', function (): void {
    $p = characters()->createDraft(9218);
    $p->level = 3;
    $p->exp = 1200;
    $p->save();

    expect(characters()->nextExpThreshold($p->fresh()))->toBeNull();

    config(['bot.demo.enabled' => false]);

    expect(characters()->nextExpThreshold($p->fresh()))->not->toBeNull();
});
