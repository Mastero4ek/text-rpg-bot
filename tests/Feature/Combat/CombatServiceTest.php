<?php

declare(strict_types=1);

use App\Enums\StanceEnum;
use App\Enums\ZoneEnum;
use App\Support\Game\Mf;

it('makeWoodenSoldier from enemies.json', function (): void {
    $e = combat()->makeWoodenSoldier();
    $cfg = gameConfig()->enemies()['woodenSoldier'];

    expect($e->level)->toBe($cfg['level'])
        ->and($e->maxHp)->toBe($cfg['maxHp'])
        ->and($e->currentHp)->toBe($cfg['maxHp'])
        ->and($e->strength)->toBe($cfg['strength']);
});

it('makeMob scales with level', function (): void {
    $level = 3;
    $e = combat()->makeMob($level);
    $w = gameConfig()->enemies()['wanderer'];
    $s = $w['statBase'] + $level * $w['statPerLevel'];

    expect($e->strength)->toBe($s)
        ->and($e->maxHp)->toBe($w['hpBase'] + $s * $w['hpPerStat'])
        ->and($e->weaponDamage)->toBe($level * $w['weaponDamagePerLevel'])
        ->and($e->name)->toBe(__('combat.enemy_wanderer', ['level' => $level]))
        ->and($e->name)->toContain('ур. ' . $level);
});

it('pveRewards in configured range', function (): void {
    $r = gameConfig()->combat()['pveRewards'];

    for ($i = 0; $i < 20; $i++) {
        $reward = combat()->pveRewards(2);
        expect($reward['exp'])->toBe($r['expBase'] + 2 * $r['expPerLevel'])
            ->and($reward['silver'])->toBeGreaterThanOrEqual($r['silverMin'])
            ->and($reward['silver'])->toBeLessThanOrEqual($r['silverMax']);
    }
});

it('block without pierce deals zero', function (): void {
    fakeRandom([0.99]);
    $hit = combat()->calculateHit(
        fighter(['name' => 'Atk', 'stance' => StanceEnum::ATTACK]),
        fighter(['name' => 'Def', 'stance' => StanceEnum::DEFEND]),
        ZoneEnum::HEAD,
        [ZoneEnum::HEAD],
    );

    expect($hit->blocked)->toBeTrue()
        ->and($hit->pierced)->toBeFalse()
        ->and($hit->dmg)->toBe(0);
});

it('block with pierce deals damage', function (): void {
    fakeRandom([0.0, 0.5]);
    $hit = combat()->calculateHit(
        fighter([
            'name' => 'Atk',
            'stance' => StanceEnum::ATTACK,
            'instinct' => 20,
            'weaponMf' => new Mf(0, 0, 50, 0),
        ]),
        fighter(['name' => 'Def', 'stance' => StanceEnum::DEFEND, 'instinct' => 1]),
        ZoneEnum::CHEST,
        [ZoneEnum::CHEST],
    );

    expect($hit->pierced)->toBeTrue()
        ->and($hit->dmg)->toBeGreaterThan(0);
});

it('dodge when not blocked', function (): void {
    fakeRandom([0.0]);
    $hit = combat()->calculateHit(
        fighter(['name' => 'Atk', 'agility' => 1, 'stance' => StanceEnum::ATTACK]),
        fighter([
            'name' => 'Def',
            'agility' => 30,
            'stance' => StanceEnum::DEFEND,
            'weaponMf' => new Mf(40, 0, 0, 0),
        ]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    expect($hit->dodged)->toBeTrue()
        ->and($hit->dmg)->toBe(0);
});

it('clean hit deals damage', function (): void {
    fakeRandom([0.99, 0.5]);
    $hit = combat()->calculateHit(
        fighter(['name' => 'Atk', 'strength' => 10, 'stance' => StanceEnum::ATTACK]),
        fighter(['name' => 'Def', 'agility' => 0, 'stance' => StanceEnum::ATTACK]),
        ZoneEnum::BELLY,
        [ZoneEnum::HEAD],
    );

    expect($hit->dodged)->toBeFalse()
        ->and($hit->blocked)->toBeFalse()
        ->and($hit->dmg)->toBeGreaterThan(0);
});

it('blocks when attack hits any of two shield zones', function (): void {
    fakeRandom([0.99]);
    $hit = combat()->calculateHit(
        fighter(['name' => 'Atk', 'stance' => StanceEnum::ATTACK]),
        fighter(['name' => 'Def', 'stance' => StanceEnum::DEFEND]),
        ZoneEnum::LEGS,
        [ZoneEnum::HEAD, ZoneEnum::LEGS],
    );

    expect($hit->blocked)->toBeTrue()
        ->and($hit->dmg)->toBe(0);
});

it('subtracts zone armor from hit damage', function (): void {
    fakeRandom([0.99, 0.5]);
    $without = combat()->calculateHit(
        fighter(['name' => 'Atk', 'strength' => 10, 'stance' => StanceEnum::ATTACK]),
        fighter(['name' => 'Def', 'agility' => 0, 'stance' => StanceEnum::ATTACK]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    fakeRandom([0.99, 0.5]);
    $withArmor = combat()->calculateHit(
        fighter(['name' => 'Atk', 'strength' => 10, 'stance' => StanceEnum::ATTACK]),
        fighter([
            'name' => 'Def',
            'agility' => 0,
            'stance' => StanceEnum::ATTACK,
            'armorByZone' => [
                'HEAD' => 3,
                'CHEST' => 0,
                'BELLY' => 0,
                'LEGS' => 0,
            ],
        ]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    expect($withArmor->dmg)->toBe(max(1, $without->dmg - 3));
});

it('lands open crit when not blocked and crit roll succeeds', function (): void {
    fakeRandom([0.99, 0.0]);

    $hit = combat()->calculateHit(
        fighter([
            'name' => 'Atk',
            'stance' => StanceEnum::ATTACK,
            'instinct' => 20,
            'weaponMf' => new Mf(0, 0, 50, 0),
        ]),
        fighter(['name' => 'Def', 'agility' => 0, 'instinct' => 1, 'stance' => StanceEnum::ATTACK]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    expect($hit->critical)->toBeTrue()
        ->and($hit->pierced)->toBeFalse()
        ->and($hit->blocked)->toBeFalse()
        ->and($hit->dodged)->toBeFalse()
        ->and($hit->dmg)->toBeGreaterThan(0)
        ->and($hit->logLine)->toContain('крит');
});

it('keeps stance damageMult when stamina is zero', function (): void {
    fakeRandom([0.99, 0.99, 0.5]);
    $attack = combat()->calculateHit(
        fighter([
            'name' => 'Atk',
            'strength' => 10,
            'stance' => StanceEnum::ATTACK,
            'stamina' => 0,
            'maxStamina' => 30,
        ]),
        fighter(['name' => 'Def', 'agility' => 0, 'stance' => StanceEnum::ATTACK, 'stamina' => 0, 'maxStamina' => 30]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    fakeRandom([0.99, 0.99, 0.5]);
    $defend = combat()->calculateHit(
        fighter([
            'name' => 'Atk',
            'strength' => 10,
            'stance' => StanceEnum::DEFEND,
            'stamina' => 0,
            'maxStamina' => 30,
        ]),
        fighter(['name' => 'Def', 'agility' => 0, 'stance' => StanceEnum::ATTACK, 'stamina' => 0, 'maxStamina' => 30]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    expect($attack->dmg)->toBeGreaterThan($defend->dmg);
});

it('adds stamina extras for crit pierce and dodge', function (): void {
    $stamina = gameConfig()->combat()['stamina'];

    expect(combat()->staminaDrainForAttacker(StanceEnum::ATTACK, true, false))
        ->toBe($stamina['drainAttack'] + $stamina['drainExtraOnCrit'])
        ->and(combat()->staminaDrainForAttacker(StanceEnum::ATTACK, false, true))
        ->toBe($stamina['drainAttack'] + $stamina['drainExtraOnCrit'])
        ->and(combat()->staminaDrainForAttacker(StanceEnum::DEFEND, false, false))
        ->toBe($stamina['drainDefend'])
        ->and(combat()->staminaDrainForDefender(true))
        ->toBe($stamina['drainDefend'] + $stamina['drainExtraOnDodge'])
        ->and(combat()->staminaDrainForDefender(false))
        ->toBe($stamina['drainDefend']);
});

it('boosts dodge chance in defend stance versus attack stance', function (): void {
    // DEFEND dodge chance ~26.5%, ATTACK ~19.5% with these stats — land between them.
    fakeRandom([0.22]);

    $fromDefend = combat()->calculateHit(
        fighter(['name' => 'Atk', 'agility' => 1, 'stance' => StanceEnum::ATTACK]),
        fighter([
            'name' => 'Def',
            'agility' => 8,
            'stance' => StanceEnum::DEFEND,
            'weaponMf' => new Mf(10, 0, 0, 0),
        ]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    fakeRandom([0.22]);

    $fromAttack = combat()->calculateHit(
        fighter(['name' => 'Atk', 'agility' => 1, 'stance' => StanceEnum::ATTACK]),
        fighter([
            'name' => 'Def',
            'agility' => 8,
            'stance' => StanceEnum::ATTACK,
            'weaponMf' => new Mf(10, 0, 0, 0),
        ]),
        ZoneEnum::HEAD,
        [ZoneEnum::LEGS],
    );

    expect($fromDefend->dodged)->toBeTrue()
        ->and($fromAttack->dodged)->toBeFalse();
});
