<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;

it('createDraft from onboarding.start', function (): void {
    $cfg = gameConfig()->onboarding()['start'];
    $p = characters()->createDraft(1001);

    expect($p->gold)->toBe($cfg['gold'])
        ->and($p->strength)->toBe($cfg['strength'])
        ->and($p->stat_points)->toBe($cfg['statPoints'])
        ->and($p->level)->toBe($cfg['level'])
        ->and($p->onboarding_step)->toBe(OnboardingStepEnum::NICK)
        ->and($p->current_hp)->toBe(characters()->baseMaxHp($cfg['vitality']));
});

it('maxHp includes armor bonus', function (): void {
    $p = characters()->createDraft(1002);
    $without = characters()->maxHp($p);
    $charCfg = gameConfig()->character()['maxHp'];

    expect($without)->toBe($charCfg['base'] + $p->vitality * $charCfg['perVitality']);

    $p->armor_id = shopCatalog()->mailShirtId();
    expect(characters()->maxHp($p))->toBe($without + shopCatalog()->mailShirt()->statBonus);
});

it('applyRegen restores over time', function (): void {
    $p = characters()->createDraft(1003);
    $p->current_hp = 1;
    $p->last_hp_update = now()->subSeconds(100);
    $p->save();

    $regen = characters()->applyRegen($p);
    expect($regen->current_hp)->toBeGreaterThan(1);
});

it('tryLevelUp spends exp and grants points', function (): void {
    $p = characters()->createDraft(1004);
    $p->level = 1;
    $p->exp = characters()->expNeed(1);
    $p->stat_points = 0;

    expect(characters()->tryLevelUp($p))->toBeTrue()
        ->and($p->level)->toBe(2)
        ->and($p->exp)->toBe(0)
        ->and($p->stat_points)->toBe(gameConfig()->character()['level']['statPointsPerLevel']);
});

it('spendStatPoint vitality bumps hp', function (): void {
    $p = characters()->createDraft(1005);
    $before = $p->current_hp;
    $vit = $p->vitality;
    $res = characters()->spendStatPoint($p, StatKeyEnum::VITALITY->value);

    expect($res->ok)->toBeTrue()
        ->and($res->character->vitality)->toBe($vit + 1)
        ->and($res->character->current_hp)->toBe(
            $before + gameConfig()->character()['statSpend']['vitalityHpGain']
        );
});

it('spendStatPoint rejects bad or empty', function (): void {
    $p = characters()->createDraft(1006);
    $p->stat_points = 0;
    $p->save();

    expect(characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->ok)->toBeFalse();

    $p->stat_points = 1;
    expect(characters()->spendStatPoint($p, 'luck')->ok)->toBeFalse();
});

it('profileText includes name', function (): void {
    $p = characters()->createDraft(1007);
    $p->username = 'TestHero';
    $p->save();

    expect(characters()->profileText($p))->toContain('TestHero');
});
