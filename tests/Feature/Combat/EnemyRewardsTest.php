<?php

declare(strict_types=1);

use App\Actions\Enemy\EnemyApplyWinLootAction;
use App\Models\Enemy\EnemyCatalog;

it('computes exp from player level and template pct', function (): void {
    $p = characters()->createDraft(7201);
    $p->level = 2;
    $p->save();
    $catalog = EnemyCatalog::factory()->create([
        'reward_exp_pct' => 50,
        'reward_silver_min' => 4,
        'reward_silver_max' => 4,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog, $p);
    $r = gameConfig()->combat()['pveRewards'];
    $expBase = $r['expBase'] + $p->level * $r['expPerLevel'];

    expect(combat()->pveRewards($p, $enemy)['exp'])->toBe((int) floor($expBase * 50 / 100))
        ->and(combat()->pveRewards($p, $enemy)['silver'])->toBe(4);
});

it('rolls silver inside template range', function (): void {
    $p = characters()->createDraft(7202);
    $catalog = EnemyCatalog::factory()->create([
        'reward_exp_pct' => 0,
        'reward_silver_min' => 10,
        'reward_silver_max' => 20,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog, $p);

    for ($i = 0; $i < 20; $i++) {
        $reward = combat()->pveRewards($p, $enemy);
        expect($reward['silver'])->toBeGreaterThanOrEqual(10)
            ->and($reward['silver'])->toBeLessThanOrEqual(20);
    }
});

it('does not grant loot on defeat', function (): void {
    $p = characters()->createDraft(7203);
    $p->silver = 0;
    $p->exp = 0;
    $p->save();
    $startSilver = $p->silver;
    $startExp = $p->exp;

    expect($p->fresh()->silver)->toBe($startSilver)
        ->and($p->fresh()->exp)->toBe($startExp);
});

it('tutorial win advances overseer train without mid rewards', function (): void {
    $start = gameConfig()->character()['start'];
    $p = cityArrived(7204, 'TutWin');
    $p->silver = $start['silver'];
    $p->exp = $start['exp'];
    $p->save();

    expect(app(App\Quests\OverseerQuest::class)->accept($p)->ok)->toBeTrue();
    expect(app(App\Quests\OverseerQuest::class)->startFight($p->fresh())->ok)->toBeTrue();
    $res = app(App\Quests\OverseerQuest::class)->advanceAfterWin($p->fresh());

    expect($res->ok)->toBeTrue()
        ->and($res->character?->overseer_train_step)->toBe(App\Enums\Quest\OverseerTrainStepEnum::Stats)
        ->and($res->character?->silver)->toBe($start['silver'])
        ->and($res->character?->exp)->toBe($start['exp']);
});

it('win loot action grants template silver', function (): void {
    $p = characters()->createDraft(7205);
    $p->silver = 0;
    $p->save();
    $catalog = EnemyCatalog::factory()->create([
        'reward_exp_pct' => 0,
        'reward_silver_min' => 7,
        'reward_silver_max' => 7,
    ]);
    $enemy = enemies()->makeFromCatalog($catalog, $p);
    $loot = app(EnemyApplyWinLootAction::class)->handle($p, $enemy);

    expect($loot['silver'])->toBe(7)
        ->and($loot['exp'])->toBe(0)
        ->and(characters()->findByTgId($p->tg_id)->silver)->toBe(7);
});
