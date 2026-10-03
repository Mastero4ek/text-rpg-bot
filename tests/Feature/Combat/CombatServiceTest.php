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
        ->and($e->weaponDamage)->toBe($level * $w['weaponDamagePerLevel']);
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
        ZoneEnum::HEAD,
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
        ZoneEnum::CHEST,
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
        ZoneEnum::LEGS,
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
        ZoneEnum::HEAD,
    );

    expect($hit->dodged)->toBeFalse()
        ->and($hit->blocked)->toBeFalse()
        ->and($hit->dmg)->toBeGreaterThan(0);
});
