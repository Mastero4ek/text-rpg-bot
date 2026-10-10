<?php

declare(strict_types=1);

use App\Enums\Combat\StanceEnum;
use App\Enums\Combat\ZoneEnum;
use App\Enums\Equipment\SlotEnum;
use App\Enums\Fight\FightStepEnum;
use App\Enums\Fight\PlayerAttackEnum;
use App\Services\Backpack\LoadoutService;
use App\Services\Fight\FightRoundService;
use Illuminate\Support\Facades\Bus;

it('splits loadout mf by body and each hand', function (): void {
    $p = characters()->createDraft(9201);
    $p->level = 1;
    $p->agility = 10;
    $p->save();

    $p = giveAndEquipStarterKnuckles($p);
    $p = equipItemToSlot($p, 'knife_0', SlotEnum::LEFT_HAND);
    $p = equipItemToSlot($p, 'focus_0', SlotEnum::RING_1);

    $loadout = app(LoadoutService::class)->forCharacter($p);
    $knuckles = shopCatalog()->findItem(shopCatalog()->starterKnucklesId());
    $knife = shopCatalog()->findItem('knife_0');
    $ring = shopCatalog()->findItem('focus_0');

    expect($loadout->mainHandMf->toArray())->toBe($knuckles->mf->toArray())
        ->and($loadout->offHandMf->toArray())->toBe($knife->mf->toArray())
        ->and($loadout->bodyMf->dodge)->toBe($ring->mf->dodge)
        ->and($loadout->mfForMainHandAttack()->crit)->toBe($knuckles->mf->crit + $ring->mf->crit)
        ->and($loadout->mfForMainHandAttack()->crit)->not->toBe($loadout->mf->crit)
        ->and($loadout->mfForOffHandAttack()->crit)->toBe($knife->mf->crit + $ring->mf->crit)
        ->and($loadout->mf->crit)->toBe($knuckles->mf->crit + $knife->mf->crit + $ring->mf->crit)
        ->and($loadout->mf->dodge)->toBe(
            $knuckles->mf->dodge + $knife->mf->dodge + $ring->mf->dodge
        );
});

it('drains attacker and defender stamina on a clean exchange', function (): void {
    Bus::fake();
    $stamina = gameConfig()->combat()['stamina'];

    $p = giveAndEquipStarterKnuckles(characters()->createDraft(9202));
    $p->username = 'StaminaHero';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $enemy['stamina'] = 60;
    $enemy['maxStamina'] = 60;
    $fight->enemy = $enemy;
    $fight->player_hp = 500;
    $fight->player_max_hp = 500;
    $fight->player_stamina = 60;
    $fight->player_max_stamina = 60;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    // stance ATTACK, enemyAtk HEAD, enemyDef LEGS, weapon, dodge miss, crit miss, variance,
    // defender weapon, dodge miss, crit miss, variance
    fakeRandom([0.99, 0.0, 0.75, 0.0, 0.99, 0.99, 0.5, 0.0, 0.99, 0.99, 0.5]);

    $outcome = app(FightRoundService::class)->runRound($p);
    expect($outcome->kind)->toBe('continue');

    $fight->refresh();
    $enemyAfter = fights()->enemy($fight);

    expect($fight->player_stamina)->toBe(60 - $stamina['drainAttack'] - $stamina['drainDefend'])
        ->and($enemyAfter->stamina)->toBe(60 - $stamina['drainDefend'] - $stamina['drainAttack']);
});

it('adds attacker extra drain on pierce and defender extra on dodge', function (): void {
    Bus::fake();
    $stamina = gameConfig()->combat()['stamina'];

    $p = giveAndEquipStarterKnuckles(characters()->createDraft(9203));
    $p->username = 'PierceDrain';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['current_hp'] = 500;
    $enemy['maxHp'] = 500;
    $enemy['stamina'] = 60;
    $enemy['maxStamina'] = 60;
    $fight->enemy = $enemy;
    $fight->player_hp = 500;
    $fight->player_max_hp = 500;
    $fight->player_stamina = 60;
    $fight->player_max_stamina = 60;
    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->step = FightStepEnum::DEFEND;
    $fight->save();

    // blocked pierce success, then enemy open hit lands
    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.0, 0.5, 0.0, 0.99, 0.99, 0.5]);

    app(FightRoundService::class)->runRound($p);
    $fight->refresh();

    expect($fight->player_stamina)->toBe(
        60 - ($stamina['drainAttack'] + $stamina['drainExtraOnCrit']) - $stamina['drainDefend']
    )
        ->and($fight->pierce_count)->toBe(1);

    $fight->player_stance = StanceEnum::ATTACK;
    $fight->player_attack = PlayerAttackEnum::HEAD;
    $fight->player_defend = ZoneEnum::CHEST;
    $fight->player_stamina = 60;
    $fight->step = FightStepEnum::DEFEND;
    $enemy = $fight->enemy;
    $enemy['stamina'] = 60;
    $fight->enemy = $enemy;
    $fight->save();

    // open hit, enemy dodges; then enemy open hit lands
    fakeRandom([0.99, 0.0, 0.75, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5]);

    app(FightRoundService::class)->runRound(characters()->findByTgId($p->tg_id));
    $fight->refresh();
    $enemyAfter = fights()->enemy($fight);

    expect($fight->player_stamina)->toBe(60 - $stamina['drainAttack'] - $stamina['drainDefend'])
        ->and($enemyAfter->stamina)->toBe(
            60 - ($stamina['drainDefend'] + $stamina['drainExtraOnDodge']) - $stamina['drainAttack']
        );
});

it('keeps player stamina on skip while enemy spends for the free hit', function (): void {
    Bus::fake();
    $stamina = gameConfig()->combat()['stamina'];

    $p = characters()->createDraft(9204);
    $p->username = 'IdleDrain';
    $p->save();

    $fight = fights()->createTraining($p, woodenSoldier($p));
    $enemy = $fight->enemy;
    $enemy['stamina'] = 60;
    $enemy['maxStamina'] = 60;
    $fight->enemy = $enemy;
    $fight->player_stamina = 60;
    $fight->player_max_stamina = 60;
    $fight->save();

    // stance ATTACK, zones, defender weapon, dodge miss, crit miss, variance
    fakeRandom([0.99, 0.0, 0.0, 0.0, 0.99, 0.99, 0.5]);

    $outcome = app(FightRoundService::class)->runSkipRound($p);
    expect($outcome->kind)->toBe('continue');

    $fight = $outcome->fight;
    $enemyAfter = fights()->enemy($fight);

    expect($fight->player_stamina)->toBe(60)
        ->and($enemyAfter->stamina)->toBe(60 - $stamina['drainAttack'])
        ->and($fight->log)->toContain(__('combat.turn_timeout'));
});
