<?php

declare(strict_types=1);

use App\Enums\OnboardingStepEnum;
use App\Enums\StatKeyEnum;
use App\Models\Character;

it('createDraft from onboarding.start', function (): void {
    $cfg = gameConfig()->onboarding()['start'];
    $p = characters()->createDraft(1001);

    expect($p->silver)->toBe($cfg['silver'])
        ->and($p->strength)->toBe($cfg['strength'])
        ->and($p->stat_points)->toBe($cfg['statPoints'])
        ->and($p->level)->toBe($cfg['level'])
        ->and($p->onboarding_step)->toBe(OnboardingStepEnum::NICK)
        ->and($p->current_hp)->toBe(characters()->baseMaxHp($cfg['vitality']))
        ->and($p->max_hp)->toBe(characters()->baseMaxHp($cfg['vitality']))
        ->and($p->current_stamina)->toBe(characters()->maxStaminaFromStrength($cfg['strength']))
        ->and($p->max_stamina)->toBe(characters()->maxStaminaFromStrength($cfg['strength']))
        ->and(inventory()->owns($p->tg_id, shopCatalog()->starterKnucklesId()))->toBeFalse();
});

it('maxHp includes armor bonus', function (): void {
    $p = characters()->createDraft(1002);
    $without = characters()->maxHp($p);
    $charCfg = gameConfig()->character()['maxHp'];

    expect($without)->toBe($p->vitality * $charCfg['perVitality']);

    inventory()->addItem($p->tg_id, shopCatalog()->mailShirtId());
    $mail = inventory()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    loadout()->equip($p, $mail->id);

    expect(characters()->maxHp($p))->toBe($without + shopCatalog()->mailShirt()->statBonus);
});

it('applyRegen restores hp over time', function (): void {
    $p = characters()->createDraft(1003);
    $p->current_hp = 1;
    $p->last_hp_update = now()->subSeconds(100);
    $p->save();

    $regen = characters()->applyRegen($p);
    expect($regen->current_hp)->toBeGreaterThan(1);
});

it('applyRegen restores stamina over time', function (): void {
    $p = characters()->createDraft(1013);
    $p->current_stamina = 1;
    $p->last_stamina_update = now()->subSeconds(100);
    $p->save();

    $regen = characters()->applyRegen($p);
    expect($regen->current_stamina)->toBeGreaterThan(1);
});

it('applyRegen persists last update timestamps when resources are full', function (): void {
    $p = characters()->createDraft(1014);
    $oldHp = now()->subHour();
    $oldStamina = now()->subHour();
    $p->last_hp_update = $oldHp;
    $p->last_stamina_update = $oldStamina;
    $p->save();

    $regen = characters()->applyRegen($p->fresh());
    $fresh = Character::query()->findOrFail($p->tg_id);

    expect($fresh->last_hp_update->greaterThan($oldHp))->toBeTrue()
        ->and($fresh->last_stamina_update->greaterThan($oldStamina))->toBeTrue()
        ->and($regen->current_hp)->toBe($p->current_hp)
        ->and($regen->current_stamina)->toBe($p->current_stamina);
});

it('applyExperienceThresholds grants ups and levels', function (): void {
    $p = characters()->createDraft(1004);
    $p->stat_points = 0;
    $p->silver = 0;
    $p->save();

    $oldExp = $p->exp;
    $p->exp = 200;
    $applied = characters()->applyExperienceThresholds($p, $oldExp, $p->exp);
    $p->save();

    expect($applied)->toBeTrue()
        ->and($p->level)->toBe(1)
        ->and($p->stat_points)->toBe(3 + 3)
        ->and($p->exp)->toBe(200);
});

it('applyExperienceThresholds grants ten points above level threshold', function (): void {
    $p = characters()->createDraft(1015);
    $p->level = 10;
    $p->exp = 12650;
    $p->stat_points = 0;
    $p->silver = 0;
    $p->save();

    $applied = characters()->applyExperienceThresholds($p, 12650, 13200);
    $p->exp = 13200;
    $p->save();

    expect($applied)->toBeTrue()
        ->and($p->level)->toBe(11)
        ->and($p->stat_points)->toBe(10);
});

it('applyExperienceThresholds is no-op at max level but grantExp still stores exp', function (): void {
    $p = characters()->createDraft(1016);
    $p->level = 15;
    $p->exp = 24000;
    $p->stat_points = 0;
    $p->save();

    $beforePoints = $p->stat_points;
    $p = characters()->grantExp($p, 100);

    expect($p->level)->toBe(15)
        ->and($p->exp)->toBe(24100)
        ->and($p->stat_points)->toBe($beforePoints);
});

it('spendStatPoint vitality bumps hp by delta max', function (): void {
    $p = characters()->createDraft(1005);
    $beforeHp = $p->current_hp;
    $beforeMax = $p->max_hp;
    $vit = $p->vitality;
    $per = gameConfig()->character()['maxHp']['perVitality'];
    $res = characters()->spendStatPoint($p, StatKeyEnum::VITALITY->value);

    expect($res->ok)->toBeTrue()
        ->and($res->character->vitality)->toBe($vit + 1)
        ->and($res->character->current_hp)->toBe($beforeHp + $per)
        ->and($res->character->max_hp)->toBe($beforeMax + $per);
});

it('spendStatPoint strength bumps stamina by delta max', function (): void {
    $p = characters()->createDraft(1017);
    $beforeStamina = $p->current_stamina;
    $beforeMax = $p->max_stamina;
    $str = $p->strength;
    $per = gameConfig()->combat()['stamina']['maxPerStrength'];
    $res = characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value);

    expect($res->ok)->toBeTrue()
        ->and($res->character->strength)->toBe($str + 1)
        ->and($res->character->current_stamina)->toBe($beforeStamina + $per)
        ->and($res->character->max_stamina)->toBe($beforeMax + $per);
});

it('spendStatPoint rejects bad or empty', function (): void {
    $p = characters()->createDraft(1006);
    $p->stat_points = 0;
    $p->save();

    expect(characters()->spendStatPoint($p, StatKeyEnum::STRENGTH->value)->ok)->toBeFalse();

    $p->stat_points = 1;
    expect(characters()->spendStatPoint($p, 'luck')->ok)->toBeFalse();
});

it('resetStats returns body to start and totalEarned points', function (): void {
    $start = gameConfig()->onboarding()['start'];
    $p = characters()->createDraft(1018);
    $p->exp = 200;
    $p->level = 1;
    $p->stat_points = 0;
    $p->strength = 9;
    $p->agility = 8;
    $p->instinct = 7;
    $p->vitality = 6;
    $p->save();

    characters()->grantStatPoints($p, 5);
    $p = $p->fresh();
    $earned = characters()->totalEarnedStatPoints($p);

    $reset = characters()->resetStats($p);

    expect($reset->strength)->toBe($start['strength'])
        ->and($reset->agility)->toBe($start['agility'])
        ->and($reset->instinct)->toBe($start['instinct'])
        ->and($reset->vitality)->toBe($start['vitality'])
        ->and($reset->stat_points)->toBe($earned)
        ->and($reset->stat_points)->toBe($start['statPoints'] + 3 + 3)
        ->and($reset->level)->toBe(1)
        ->and($reset->exp)->toBe(200)
        ->and($reset->max_hp)->toBe(characters()->baseMaxHp($start['vitality']))
        ->and($reset->max_stamina)->toBe(characters()->maxStaminaFromStrength($start['strength']));
});

it('resetStatsForGold spends gold and fails without enough', function (): void {
    $cost = characters()->statResetGoldCost();
    $p = characters()->createDraft(1019);
    $p->gold = 0;
    $p->strength = 9;
    $p->save();

    expect(characters()->resetStatsForGold($p)->ok)->toBeFalse();

    $p->gold = $cost;
    $p->save();
    $res = characters()->resetStatsForGold($p->fresh());

    expect($res->ok)->toBeTrue()
        ->and($res->character->gold)->toBe(0)
        ->and($res->character->strength)->toBe(gameConfig()->onboarding()['start']['strength']);
});

it('profileText includes name and next exp threshold', function (): void {
    $p = characters()->createDraft(1007);
    $p->username = 'TestHero';
    $p->exp = 0;
    $p->save();

    $text = characters()->profileText($p);

    expect($text)->toContain('TestHero')
        ->and($text)->toContain('0/50');
});

it('statsScreenText shows body and total mf blocks', function (): void {
    $p = characters()->createDraft(1020);
    $p->stat_points = 2;
    $p->save();

    inventory()->addItem($p->tg_id, shopCatalog()->mailShirtId());
    $mail = inventory()->findOwned($p->tg_id, shopCatalog()->mailShirtId());
    loadout()->equip($p, $mail->id);

    $fresh = $p->fresh();
    $text = characters()->statsScreenText($fresh);
    $bodyHp = characters()->baseMaxHp($fresh->vitality);
    $totalHp = characters()->maxHp($fresh);
    $gearHp = shopCatalog()->mailShirt()->statBonus;

    expect($text)->toContain('Свободно: 2')
        ->and($text)->toContain('МФ тела:')
        ->and($text)->toContain('Итог (тело + вещи):')
        ->and($text)->toContain('жизнь ' . $bodyHp)
        ->and($text)->toContain('жизнь ' . $totalHp)
        ->and($text)->toContain('HP от шмота: +' . $gearHp);
});

it('nextExpThreshold is null at max level', function (): void {
    $p = characters()->createDraft(1021);
    $p->level = 15;
    $p->exp = 24000;
    $p->save();

    expect(characters()->nextExpThreshold($p))->toBeNull();
});
